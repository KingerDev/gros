<?php

/*
|--------------------------------------------------------------------------
| Groš — číselníky a default strom kategórií
|--------------------------------------------------------------------------
| Kategórie sú od verzie so správou kategórií uložené v DB (per používateľ,
| stromová štruktúra). Tu je len DEFAULT strom, ktorý sa naseeduje každému
| novému používateľovi (App\Support\DefaultCategories). Používateľ si ho
| potom môže ľubovoľne upraviť.
*/

return [

    // Druhy investícií
    'kind_labels' => ['etf' => 'ETF', 'stock' => 'Akcia', 'crypto' => 'Krypto'],

    // Paleta na auto-priradenie farieb (účty, investície, úvery) a na výber pri kategóriách
    'palette' => ['#4c8dff', '#9775fa', '#ff6b9d', '#22b8cf', '#f7931a', '#627eea', '#14b8a6', '#20c997', '#f06595', '#748ffc', '#ff922b', '#f0a020'],

    // Ponuka accent farieb v nastaveniach
    'accent_options' => ['#6c5ce7', '#2a7de1', '#e8544e', '#0fa3b1', '#f0692a'],

    /*
    |--------------------------------------------------------------------------
    | Referenčné dáta pre odhad života po škole
    |--------------------------------------------------------------------------
    | Slúžia len ako východisko, keď používateľ nemá vlastný odhad. Sú to
    | verejné štatistiky, nie predpoveď — preto je pri nich uvedený zdroj
    | aj dátum, nech je zrejmé, kedy prestali byť aktuálne.
    */
    'reference' => [
        'rent' => [
            'source' => 'Deloitte Rent Index Q1 2026',
            'url' => 'https://www.deloitte.com/cz-sk/sk/Industries/real-estate/collections/rent-index.html',
            'as_of' => '2026-Q1',
            // priemerná ponuková cena za byt (EUR/mesiac)
            'sr_average' => 736,
            'by_size' => [
                '1' => ['label' => '1-izbový', 'rent' => 468],
                '2' => ['label' => '2-izbový', 'rent' => 655],
                '3' => ['label' => '3-izbový', 'rent' => 858],
            ],
            'by_city' => [
                'bratislava' => ['label' => 'Bratislava', 'rent' => 948],
                'kosice' => ['label' => 'Košice', 'rent' => 736],
                'nitra' => ['label' => 'Nitra', 'rent' => 664],
                'presov' => ['label' => 'Prešov', 'rent' => 650],
                'trnava' => ['label' => 'Trnava', 'rent' => 629],
                'banska_bystrica' => ['label' => 'Banská Bystrica', 'rent' => 597],
                'poprad' => ['label' => 'Poprad', 'rent' => 580],
            ],
        ],
        'graduate_income' => [
            'source' => 'Trendy práce / ISP — uplatnenie absolventov',
            'url' => 'https://www.trendyprace.sk/sk/absolventi/sk-trendy/mzda',
            // hrubé mesačné mzdy; čistá mzda je zhruba 78 % z hrubej
            'gross' => [
                'bachelor' => 1_299,
                'master' => 1_418,
                'experienced' => 1_536,
            ],
            'net_ratio' => 0.78,
        ],
    ],

    // Default strom kategórií pre nového používateľa.
    // Skupina (parent) → podkategórie (children). Skupiny slúžia ako hlavičky,
    // transakcia sa priraďuje k podkategórii (listu). Podkategórie dedia farbu
    // skupiny (ako vo Wallete). Vychádza z taxonómie Wallet by BudgetBakers,
    // preložené do slovenčiny; navyše skupiny „Jedlo a pitie" a „Ostatné".
    'default_categories' => [
        // ---- VÝDAVKY ----
        ['name' => 'Jedlo a pitie', 'type' => 'expense', 'color' => '#ff922b', 'icon' => '🍽️', 'children' => [
            ['name' => 'Potraviny', 'icon' => '🛒'],
            ['name' => 'Reštaurácia, fast-food', 'icon' => '🍔'],
            ['name' => 'Bar, kaviareň', 'icon' => '☕'],
        ]],
        ['name' => 'Nákupy', 'type' => 'expense', 'color' => '#4c8dff', 'icon' => '🛍️', 'children' => [
            ['name' => 'Drogéria', 'icon' => '🧴'],
            ['name' => 'Voľný čas', 'icon' => '😊'],
            ['name' => 'Papiernictvo, náradie', 'icon' => '✂️'],
            ['name' => 'Darčeky, radosť', 'icon' => '🎁'],
            ['name' => 'Elektronika, príslušenstvo', 'icon' => '💻'],
            ['name' => 'Domáce zvieratá', 'icon' => '🐾'],
            ['name' => 'Dom, záhrada', 'icon' => '🏡'],
            ['name' => 'Deti', 'icon' => '🧸'],
            ['name' => 'Zdravie a krása', 'icon' => '💄'],
            ['name' => 'Šperky, doplnky', 'icon' => '💎'],
            ['name' => 'Oblečenie a obuv', 'icon' => '👕'],
        ]],
        ['name' => 'Bývanie', 'type' => 'expense', 'color' => '#fd7e14', 'icon' => '🏠', 'children' => [
            ['name' => 'Poistenie nehnuteľnosti', 'icon' => '🛡️'],
            ['name' => 'Údržba, opravy', 'icon' => '🔨'],
            ['name' => 'Služby', 'icon' => '⚙️'],
            ['name' => 'Energie, inkaso', 'icon' => '⚡'],
            ['name' => 'Hypotéka', 'icon' => '🏦'],
            ['name' => 'Nájom', 'icon' => '🔑'],
        ]],
        ['name' => 'Doprava', 'type' => 'expense', 'color' => '#64748b', 'icon' => '🚌', 'children' => [
            ['name' => 'Služobné cesty', 'icon' => '💼'],
            ['name' => 'Diaľková doprava', 'icon' => '✈️'],
            ['name' => 'Taxi', 'icon' => '🚕'],
            ['name' => 'MHD', 'icon' => '🚊'],
        ]],
        ['name' => 'Vozidlo', 'type' => 'expense', 'color' => '#9c36b5', 'icon' => '🚗', 'children' => [
            ['name' => 'Lízing', 'icon' => '💳'],
            ['name' => 'Poistenie vozidla', 'icon' => '🪪'],
            ['name' => 'Prenájom', 'icon' => '🔑'],
            ['name' => 'Údržba vozidla', 'icon' => '🔧'],
            ['name' => 'Parkovanie', 'icon' => '🅿️'],
            ['name' => 'Pohonné hmoty', 'icon' => '⛽'],
        ]],
        ['name' => 'Život a zábava', 'type' => 'expense', 'color' => '#40c057', 'icon' => '🧍', 'children' => [
            ['name' => 'Lotéria, stávky', 'icon' => '🎲'],
            ['name' => 'Alkohol, tabak', 'icon' => '🍺'],
            ['name' => 'Charita, dary', 'icon' => '🎁'],
            ['name' => 'Dovolenka, výlety, hotely', 'icon' => '🏝️'],
            ['name' => 'TV, streaming', 'icon' => '📺'],
            ['name' => 'Knihy, audio, predplatné', 'icon' => '📚'],
            ['name' => 'Vzdelávanie, rozvoj', 'icon' => '🎓'],
            ['name' => 'Koníčky', 'icon' => '🎨'],
            ['name' => 'Životné udalosti', 'icon' => '🎂'],
            ['name' => 'Kultúra, športové podujatia', 'icon' => '🎭'],
            ['name' => 'Aktívny šport, fitness', 'icon' => '🏋️'],
            ['name' => 'Wellness, krása', 'icon' => '🌸'],
            ['name' => 'Zdravotná starostlivosť, lekár', 'icon' => '🩺'],
        ]],
        ['name' => 'Komunikácia, PC', 'type' => 'expense', 'color' => '#4c6ef5', 'icon' => '🖥️', 'children' => [
            ['name' => 'Poštové služby', 'icon' => '✉️'],
            ['name' => 'Softvér, aplikácie, hry', 'icon' => '🎮'],
            ['name' => 'Internet', 'icon' => '📶'],
            ['name' => 'Telefón, mobil', 'icon' => '📱'],
        ]],
        ['name' => 'Finančné výdavky', 'type' => 'expense', 'color' => '#12b886', 'icon' => '💰', 'children' => [
            ['name' => 'Výživné', 'icon' => '🍼'],
            ['name' => 'Poplatky', 'icon' => '🧾'],
            ['name' => 'Poradenstvo', 'icon' => '💬'],
            ['name' => 'Pokuty', 'icon' => '⚖️'],
            ['name' => 'Úvery, úroky', 'icon' => '📉'],
            ['name' => 'Poistenia', 'icon' => '🛡️'],
            ['name' => 'Dane', 'icon' => '💸'],
        ]],
        ['name' => 'Investície', 'type' => 'expense', 'is_savings' => true, 'color' => '#f06595', 'icon' => '📊', 'children' => [
            ['name' => 'Zbierky', 'icon' => '🖼️'],
            ['name' => 'Úspory', 'icon' => '💰'],
            ['name' => 'Finančné investície', 'icon' => '📈'],
            ['name' => 'Vozidlá, hnuteľný majetok', 'icon' => '🚗'],
            ['name' => 'Nehnuteľnosti', 'icon' => '🏢'],
        ]],
        ['name' => 'Ostatné', 'type' => 'expense', 'color' => '#94a3b8', 'icon' => '📦', 'children' => [
            ['name' => 'Ostatné výdavky', 'icon' => '📦'],
        ]],

        // ---- PRÍJMY ----
        ['name' => 'Príjmy', 'type' => 'income', 'color' => '#fab005', 'icon' => '💰', 'children' => [
            ['name' => 'Mzda, faktúry', 'icon' => '💵'],
            ['name' => 'Dary', 'icon' => '🎁'],
            ['name' => 'Výživné', 'icon' => '🍼'],
            ['name' => 'Vratky (daň, nákup)', 'icon' => '↩️'],
            ['name' => 'Lotéria, výhry', 'icon' => '🎲'],
            ['name' => 'Šeky, kupóny', 'icon' => '🎟️'],
            ['name' => 'Pôžičky, prenájom', 'icon' => '📥'],
            ['name' => 'Príspevky a granty', 'icon' => '✅'],
            ['name' => 'Príjem z prenájmu', 'icon' => '🏠'],
            ['name' => 'Predaj', 'icon' => '🏷️'],
            ['name' => 'Úroky, dividendy', 'icon' => '📈'],
        ]],
    ],
];
