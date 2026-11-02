<?php

namespace App\Domain\Account;

enum SignInCodeOutcome
{
    case Accepted;
    case Wrong;
    case UsedUp;
    case Expired;
}
