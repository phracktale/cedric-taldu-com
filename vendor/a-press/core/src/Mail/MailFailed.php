<?php

declare(strict_types=1);

namespace APress\Core\Mail;

use RuntimeException;

/** Le serveur a refusé ou n'a pas répondu. Le message ne contient aucun secret. */
final class MailFailed extends RuntimeException
{
}
