<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cria a tabela das sessoes do assistente.
 *
 * Numa instalacao nova o Mautic monta o schema do plugin a partir das entidades; numa
 * atualizacao ele so roda as migrations deste diretorio. Sem esta, a 1.14.0 subiria com a
 * entidade mapeada e sem tabela, e a aba de sessoes responderia erro 500 no primeiro toque.
 * O SQL e o que o Doctrine gera para `AssistantSession`, nomes de indice e de chave
 * estrangeira inclusive, para o `doctrine:schema:update --dump-sql` nao pedir nada depois.
 */
final class Version_1_14_0 extends AbstractMigration
{
    private const TABLE = 'inbox_assistant_sessions';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql(sprintf("CREATE TABLE %s (id INT UNSIGNED AUTO_INCREMENT NOT NULL, user_id INT UNSIGNED NOT NULL, agent_key VARCHAR(100) NOT NULL, title VARCHAR(160) NOT NULL, context_kind VARCHAR(16) DEFAULT NULL, context_id INT DEFAULT NULL, turns JSON NOT NULL, date_added DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', date_modified DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX inbox_assistant_session_owner (user_id, date_modified), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC", $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT FK_C386AAACA76ED395 FOREIGN KEY (user_id) REFERENCES %s (id) ON DELETE CASCADE', $table, $this->concatPrefix('users')));
    }
}
