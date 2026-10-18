<?php

namespace App\Enums;

enum AccessLinkPurpose: string
{
    case Invitation = 'invitation';
    case PasswordReset = 'password_reset';
}
