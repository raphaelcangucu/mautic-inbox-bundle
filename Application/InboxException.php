<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application;
final class InboxException extends \DomainException
{
    public function __construct(string $message, public readonly int $httpStatus = 422) { parent::__construct($message); }
}
