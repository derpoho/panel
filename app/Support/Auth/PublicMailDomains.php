<?php

namespace App\Support\Auth;

/**
 * Mail domains anyone can get an address on. Nobody can verify one of
 * these for their workspace: it would let the whole internet in.
 */
final class PublicMailDomains
{
    public const array DOMAINS = [
        'gmail.com', 'googlemail.com', 'google.com',
        'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk', 'msn.com', 'passport.com',
        'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me',
        'aol.com', 'aim.com',
        'gmx.com', 'gmx.net', 'gmx.de', 'web.de',
        'mail.com', 'email.com', 'zoho.com', 'zohomail.com', 'yandex.com', 'yandex.ru', 'mail.ru',
        'tutanota.com', 'tuta.io', 'fastmail.com', 'hey.com', 'qq.com', '163.com', '126.com',
        'btinternet.com', 'sky.com', 'virginmedia.com', 'comcast.net', 'verizon.net', 'att.net',
    ];

    public static function is(string $domain): bool
    {
        return in_array(strtolower(rtrim($domain, '.')), self::DOMAINS, true);
    }
}
