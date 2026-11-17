<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use RuntimeException;

/** The phone service did not answer in time or had a server problem, so trying again later can work. */
final class PhoneServiceUnavailable extends RuntimeException
{
}
