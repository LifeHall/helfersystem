<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\User\User;

/**
 * Kind of mail of a mail job, e.g. the shift plan or the mail to everybody of the plugin Tagesplan
 *
 * One instance is used for all recipients of a batch, so it can keep data needed for every mail
 */
interface MailJobHandler
{
    /**
     * Sends the mail to one recipient and logs errors itself
     *
     * @param array $payload Data of the job, e.g. subject and text
     */
    public function sendTo(User $user, array $payload): bool;
}
