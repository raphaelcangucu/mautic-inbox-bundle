<?php
namespace MauticPlugin\MauticInboxBundle\Application\Ai;
use Symfony\Component\Process\Process;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
class PiClient {
    public function home(): string{return getenv('INBOX_PI_HOME')?:'/home/forge/inbox-pi';}
    public function call(string $action,array $input=[]): array {
        $p=new Process(['/usr/bin/node',$this->home().'/runner.mjs',$action],$this->home());$p->setInput(json_encode($input,JSON_THROW_ON_ERROR));$p->setTimeout(110);
        try {
            $p->run();
            $out=json_decode($p->getOutput(),true,32,JSON_THROW_ON_ERROR);
            if (!$p->isSuccessful() || !is_array($out) || isset($out['error'])) {
                throw new \RuntimeException((string) ($out['error'] ?? trim($p->getErrorOutput()) ?: 'pi_process_failed'));
            }
            return $out;
        } catch (\Throwable $exception) {
            throw new InboxException('mautic.inbox.ai.pi_failed',503,$exception);
        }
    }
    public function installed(): bool{return is_file($this->home().'/node_modules/@earendil-works/pi-coding-agent/package.json');}
    public function install(): array {
        if(!is_dir($this->home()))mkdir($this->home(),0700,true);
        $p=new Process(['/usr/bin/npm','install','--save-exact','--ignore-scripts','@earendil-works/pi-coding-agent@0.85.1'],$this->home());$p->setTimeout(180);$p->run();if(!$p->isSuccessful())throw new InboxException('mautic.inbox.ai.install_failed',503);
        foreach(['runner.mjs','cms.mjs','locale.mjs','page-context.mjs'] as $file)copy(__DIR__.'/../../Runtime/'.$file,$this->home().'/'.$file);return ['installed'=>true];
    }
}
