<?php

/*
| Texty zo servera po anglicky — pre mobilnú appku na zariadeniach,
| ktoré nie sú v slovenčine/češtine (SetApiLocale).
*/
return [
    'months' => ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'months_short' => ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],

    'period' => [
        'all' => 'All time',
        '30d' => 'Last 30 days',
    ],

    'kind_labels' => ['etf' => 'ETF', 'stock' => 'Stock', 'crypto' => 'Crypto'],

    // kľúč = hodnota uložená v accounts.type (ukladá sa po slovensky)
    'account_types' => [
        'Bežný účet' => 'Checking account',
        'Sporiaci účet' => 'Savings account',
        'Karta' => 'Card',
        'Peňaženka' => 'Cash wallet',
        'Investičný účet' => 'Investment account',
    ],

    'weekly' => [
        'title' => 'Your week in Groš',
        'privacy' => 'Your weekly spending summary is ready. See how you did.',
        'none' => 'You had no expenses this week.',
        'spent' => 'You spent :amount',
        'same' => ' — the same as last week',
        'more' => ' — :pct% more than last week',
        'less' => ' — :pct% less than last week',
        'top' => ' Biggest expense: :name :amount.',
        'uncategorized' => 'Uncategorized',
    ],

    'auth' => [
        'reset_link' => 'If an account with this email exists, we have sent it a link to reset the password.',
        'apple_invalid' => 'We could not verify your Apple sign-in. Please try again.',
        'apple_no_email' => 'Apple did not share an email. Remove Groš in your Apple ID settings and sign in again.',
        'wrong_password' => 'The password is incorrect.',
        'default_name' => 'Groš user',
    ],
];
