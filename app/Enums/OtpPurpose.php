<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Signup = 'signup';
    case PasswordReset = 'password_reset';
}
