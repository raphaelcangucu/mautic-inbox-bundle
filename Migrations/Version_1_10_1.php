<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cria a tabela de presenca do atendente.
 *
 * Numa instalacao nova o Mautic monta o schema do plugin a partir das entidades, mas numa
 * atualizacao ele so roda as migrations deste diretorio: sem esta, a 1.10.0 subia com a
 * entidade mapeada e sem tabela. O SQL abaixo e o que o proprio Doctrine gera para
 * `OperatorPresence`, nomes de indice e de chave estrangeira inclusive, para que o
 * `doctrine:schema:update --dump-sql` nao fique pedindo nada depois.
 */
final class Version_1_10_1 extends AbstractMigration
{
    private const TABLE = 'inbox_operator_presence';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql(sprintf("CREATE TABLE %s (id INT UNSIGNED AUTO_INCREMENT NOT NULL, user_id INT UNSIGNED NOT NULL, available TINYINT(1) NOT NULL, date_modified DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX inbox_presence_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC", $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT FK_D4D0CB73A76ED395 FOREIGN KEY (user_id) REFERENCES %s (id) ON DELETE CASCADE', $table, $this->concatPrefix('users')));
    }
}
