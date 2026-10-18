<?php

/*
| Texty zo servera, ktoré zobrazuje web aj mobilná appka. Web beží po
| slovensky, appka podľa jazyka zariadenia (SetApiLocale).
*/
return [
    'months' => ['', 'Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún', 'Júl', 'August', 'September', 'Október', 'November', 'December'],
    'months_short' => ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'],

    'period' => [
        'all' => 'Celé obdobie',
        '30d' => 'Posledných 30 dní',
    ],

    'kind_labels' => ['etf' => 'ETF', 'stock' => 'Akcia', 'crypto' => 'Krypto'],

    // kľúč = hodnota uložená v accounts.type
    'account_types' => [
        'Bežný účet' => 'Bežný účet',
        'Sporiaci účet' => 'Sporiaci účet',
        'Karta' => 'Karta',
        'Peňaženka' => 'Peňaženka',
        'Investičný účet' => 'Investičný účet',
    ],

    'weekly' => [
        'title' => 'Tvoj týždeň v Groši',
        'privacy' => 'Tvoje týždenné zhrnutie výdavkov je pripravené. Pozri sa, ako sa ti darilo.',
        'none' => 'Tento týždeň si nemal žiadne výdavky.',
        'spent' => 'Minul si :amount',
        'same' => ' — rovnako ako minulý týždeň',
        'more' => ' — o :pct % viac ako minulý týždeň',
        'less' => ' — o :pct % menej ako minulý týždeň',
        'top' => ' Najväčší výdavok: :name :amount.',
        'uncategorized' => 'Bez kategórie',
    ],

    'auth' => [
        'reset_link' => 'Ak k tomuto e-mailu existuje účet, poslali sme naň odkaz na obnovenie hesla.',
        'apple_invalid' => 'Prihlásenie cez Apple sa nepodarilo overiť. Skús to znova.',
        'apple_no_email' => 'Apple neposlal e-mail. V nastaveniach Apple ID odober Groš a prihlás sa znova.',
        'apple_email_taken' => 'Účet s týmto e-mailom už existuje. Prihlás sa e-mailom a heslom.',
        'apple_confirm' => 'Zmazanie potvrď prihlásením cez Apple.',
        'wrong_password' => 'Nesprávne heslo.',
        'default_name' => 'Používateľ Groša',
    ],
    'investments' => [
        'limit' => 'Môžeš mať najviac :max investícií.',
        'refresh_wait' => 'Ceny sa práve aktualizovali — skús to o minútu.',
    ],
    'insights' => [
        'event' => ':label · na „:name" išlo :amount — ráta sa ako jednorazový výdavok.',
        'without_events' => ' (bez udalostí)',
        'more' => ':label · minul si:scope o :pct % viac než býva priemer.',
        'less' => ':label · minul si:scope o :pct % menej než býva priemer. 👏',
        'top_category' => ':label · najviac išlo na „:name" — :amount.',
        'biggest' => ':label · najväčší výdavok: „:name" :amount.',
        'expense' => 'výdavok',
        'rate' => ':label · miera úspor :rate %.',
    ],
    'server' => [
        'source_vanguard' => 'Vanguard — núdzový fond: výdavkový náraz a výpadok príjmu',
        'source_unemployment' => 'Dávka v nezamestnanosti od roku 2026 — 50 % DVZ prvé 3 mesiace, potom 40, 30 a 20 %',
        'average_wage_source' => 'ŠÚ SR — priemerná nominálna mesačná mzda za rok 2025 (zverejnené 3. 3. 2026)',
        'loan_growing' => 'Splátka nepokryje ani úrok — dlh pri nej rastie.',
        'portfolio_empty' => 'Zatiaľ žiadne nákupy — analýza sa zobrazí po pridaní prvej transakcie.',
        'report_month' => 'Mesiac v kocke',
        'report_year' => 'Rok v kocke',
        'report_30d' => 'Posledných 30 dní v kocke',
        'report_custom' => 'Obdobie v kocke',
        'before' => 'Vopred',
        'after' => 'Potom',
    ],
];
