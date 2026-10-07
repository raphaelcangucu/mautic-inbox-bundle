<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Command;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\{Role,User};
use Mautic\UserBundle\Model\{RoleModel,UserModel};
use Symfony\Component\Console\{Attribute\AsCommand,Command\Command,Input\InputInterface,Input\InputOption,Output\OutputInterface};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;

/** Add one limited operator through existing Mautic users/roles. No schema changes. */
#[AsCommand(name:'mautic:inbox:operator:create',description:'Create a limited Inbox operator from a private credentials file. No email is sent.')]
final class CreateOperatorCommand extends Command
{
    public function __construct(private EntityManagerInterface $em,private CorePermissions $permissions,private RoleModel $roles,private UserModel $users,private UserPasswordHasherInterface $hasher,private ConversationAccess $access) { parent::__construct(); }
    protected function configure():void { $this->addOption('credentials-file',null,InputOption::VALUE_REQUIRED,'Absolute private JSON file with username, email, password and optional scope=own|operator.'); }
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $path=$input->getOption('credentials-file');
        if (!is_string($path) || !str_starts_with($path,'/') || !is_file($path) || (fileperms($path)&0077)!==0) throw new \RuntimeException('Credentials must be an absolute private file (0600).');
        $p=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($p) || !preg_match('/^[a-z0-9][a-z0-9._-]{2,49}$/D',$p['username']??'') || !filter_var($p['email']??'',FILTER_VALIDATE_EMAIL) || !is_string($p['password']??null) || strlen($p['password'])<16 || !in_array($p['scope']??'own',['own','operator'],true)) throw new \RuntimeException('Invalid limited operator definition.');
        // Refuse to mutate an existing user, role or administrator by accident.
        $existing=$this->em->getRepository(User::class)->createQueryBuilder('u')->where('u.username = :name OR LOWER(u.email) = :email')->setParameter('name',$p['username'])->setParameter('email',strtolower($p['email']))->getQuery()->getResult();
        if ($existing) throw new \RuntimeException('Username or email already exists; no changes made.');
        $scope=$p['scope']??'own';$name='Inbox · '.($scope==='own'?'Conversas atribuídas':'Operador e fila').' · '.$p['username'];
        if ($this->em->getRepository(Role::class)->findOneBy(['name'=>$name])) throw new \RuntimeException('Role already exists; no changes made.');
        $role=(new Role())->setName($name)->setIsAdmin(false)->setDescription($scope==='own'?'Only assigned conversations. No shared waiting queue.':'Own conversations and unassigned open conversations awaiting a response.');
        $definition=['inbox:conversations'=>['view','edit','create'],'inbox:scope'=>$scope==='own'?['own']:['own','waiting'],'inbox:templates'=>['view'],'meta:messages'=>['view','edit','create'],'lead:leads'=>['viewown','editown','create'],'lead:lists'=>['viewown'],'campaign:campaigns'=>['viewown']];
        foreach($this->permissions->generatePermissions($definition) as $permission){$permission->setRole($role);$role->addPermission($permission);}
        $user=(new User())->setUsername($p['username'])->setEmail(strtolower($p['email']))->setFirstName($p['first_name']??'Apple')->setLastName($p['last_name']??'Review')->setRole($role)->setIsPublished(true);
        $user->setPassword($this->hasher->hashPassword($user,$p['password']));
        $this->em->wrapInTransaction(function()use($role,$user):void{$this->roles->saveEntity($role);$this->users->saveEntity($user);});
        $this->em->refresh($user);$actual=$this->access->scope($user);
        if ($user->isAdmin() || !$actual['own'] || $actual['all'] || $actual['waiting']!==($scope==='operator')) throw new \RuntimeException('Created role verification failed.');
        $output->writeln(json_encode(['user_id'=>$user->getId(),'username'=>$user->getUsername(),'role_id'=>$role->getId(),'scope'=>$actual,'administrator'=>false,'emailSent'=>false],JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
