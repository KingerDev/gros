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
        'apple_email_taken' => 'An account with this email already exists. Sign in with your email and password.',
        'apple_confirm' => 'Confirm the deletion with Sign in with Apple.',
        'wrong_password' => 'The password is incorrect.',
        'default_name' => 'Groš user',
    ],
    'investments' => [
        'limit' => 'You can have at most :max investments.',
        'refresh_wait' => 'Prices were just updated — try again in a minute.',
    ],
    'insights' => [
        'event' => ':label · “:name” cost :amount — counted as a one-off expense.',
        'without_events' => ' (excluding events)',
        'more' => ':label · you spent:scope :pct% more than usual.',
        'less' => ':label · you spent:scope :pct% less than usual. 👏',
        'top_category' => ':label · most went to “:name” — :amount.',
        'biggest' => ':label · biggest expense: “:name” :amount.',
        'expense' => 'expense',
        'rate' => ':label · savings rate :rate%.',
    ],
    'server' => [
        'source_vanguard' => 'Vanguard — emergency fund: spending shocks and income loss',
        'source_unemployment' => 'Slovak unemployment benefit from 2026 — 50 % of the daily base for the first 3 months, then 40, 30 and 20 %',
        'average_wage_source' => 'Statistical Office of the Slovak Republic — average nominal monthly wage for 2025 (published 3 March 2026)',
        'loan_growing' => 'The payment does not even cover the interest — the debt keeps growing.',
        'portfolio_empty' => 'No purchases yet — the analysis appears once you add your first trade.',
        'report_month' => 'Month at a glance',
        'report_year' => 'Year at a glance',
        'report_30d' => 'Last 30 days at a glance',
        'report_custom' => 'Period at a glance',
        'before' => 'Before',
        'after' => 'After',
    ],
];
