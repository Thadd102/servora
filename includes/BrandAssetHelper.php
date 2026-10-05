<?php

/**
 * BrandAssetHelper - Servora Brand Logos & Country Flags Engine
 * 
 * Provides authentic, high-definition SVG brand logos (WhatsApp, Telegram, Facebook,
 * Instagram, Google, TikTok, X, OpenAI, etc.) and crisp, pixel-perfect country flag badges.
 */

class BrandAssetHelper
{
    /**
     * Map of country codes to international dial codes and default names
     */
    private static array $countryMetadata = [
        'AE' => ['name' => 'United Arab Emirates', 'dial' => '+971'],
        'AF' => ['name' => 'Afghanistan', 'dial' => '+93'],
        'AG' => ['name' => 'Antigua and Barbuda', 'dial' => '+1268'],
        'AL' => ['name' => 'Albania', 'dial' => '+355'],
        'AM' => ['name' => 'Armenia', 'dial' => '+374'],
        'AO' => ['name' => 'Angola', 'dial' => '+244'],
        'AR' => ['name' => 'Argentina', 'dial' => '+54'],
        'AT' => ['name' => 'Austria', 'dial' => '+43'],
        'AU' => ['name' => 'Australia', 'dial' => '+61'],
        'AW' => ['name' => 'Aruba', 'dial' => '+297'],
        'AZ' => ['name' => 'Azerbaijan', 'dial' => '+994'],
        'BA' => ['name' => 'Bosnia and Herzegovina', 'dial' => '+387'],
        'BB' => ['name' => 'Barbados', 'dial' => '+1246'],
        'BD' => ['name' => 'Bangladesh', 'dial' => '+880'],
        'BE' => ['name' => 'Belgium', 'dial' => '+32'],
        'BF' => ['name' => 'Burkina Faso', 'dial' => '+226'],
        'BG' => ['name' => 'Bulgaria', 'dial' => '+359'],
        'BH' => ['name' => 'Bahrain', 'dial' => '+973'],
        'BI' => ['name' => 'Burundi', 'dial' => '+257'],
        'BJ' => ['name' => 'Benin', 'dial' => '+229'],
        'BM' => ['name' => 'Bermuda', 'dial' => '+1441'],
        'BO' => ['name' => 'Bolivia', 'dial' => '+591'],
        'BR' => ['name' => 'Brazil', 'dial' => '+55'],
        'BS' => ['name' => 'Bahamas', 'dial' => '+1'],
        'BT' => ['name' => 'Bhutan', 'dial' => '+975'],
        'BW' => ['name' => 'Botswana', 'dial' => '+267'],
        'BY' => ['name' => 'Belarus', 'dial' => '+375'],
        'BZ' => ['name' => 'Belize', 'dial' => '+501'],
        'CA' => ['name' => 'Canada', 'dial' => '+1'],
        'CD' => ['name' => 'Democratic Republic of Congo', 'dial' => '+243'],
        'CF' => ['name' => 'Central African Republic', 'dial' => '+236'],
        'CG' => ['name' => 'Republic of Congo', 'dial' => '+242'],
        'CH' => ['name' => 'Switzerland', 'dial' => '+41'],
        'CI' => ['name' => 'Ivory Coast', 'dial' => '+225'],
        'CL' => ['name' => 'Chile', 'dial' => '+56'],
        'CM' => ['name' => 'Cameroon', 'dial' => '+237'],
        'CN' => ['name' => 'China', 'dial' => '+86'],
        'CO' => ['name' => 'Colombia', 'dial' => '+57'],
        'CR' => ['name' => 'Costa Rica', 'dial' => '+506'],
        'CU' => ['name' => 'Cuba', 'dial' => '+53'],
        'CY' => ['name' => 'Cyprus', 'dial' => '+357'],
        'CZ' => ['name' => 'Czech Republic', 'dial' => '+420'],
        'DE' => ['name' => 'Germany', 'dial' => '+49'],
        'DJ' => ['name' => 'Djibouti', 'dial' => '+253'],
        'DK' => ['name' => 'Denmark', 'dial' => '+45'],
        'DM' => ['name' => 'Dominica', 'dial' => '+1767'],
        'DO' => ['name' => 'Dominican Republic', 'dial' => '+1849'],
        'DZ' => ['name' => 'Algeria', 'dial' => '+213'],
        'EC' => ['name' => 'Ecuador', 'dial' => '+593'],
        'EE' => ['name' => 'Estonia', 'dial' => '+372'],
        'EG' => ['name' => 'Egypt', 'dial' => '+20'],
        'ES' => ['name' => 'Spain', 'dial' => '+34'],
        'ET' => ['name' => 'Ethiopia', 'dial' => '+251'],
        'FI' => ['name' => 'Finland', 'dial' => '+358'],
        'FJ' => ['name' => 'Fiji', 'dial' => '+679'],
        'FR' => ['name' => 'France', 'dial' => '+33'],
        'GA' => ['name' => 'Gabon', 'dial' => '+241'],
        'GB' => ['name' => 'United Kingdom', 'dial' => '+44'],
        'GD' => ['name' => 'Grenada', 'dial' => '+1473'],
        'GE' => ['name' => 'Georgia', 'dial' => '+995'],
        'GF' => ['name' => 'French Guiana', 'dial' => '+594'],
        'GH' => ['name' => 'Ghana', 'dial' => '+233'],
        'GM' => ['name' => 'Gambia', 'dial' => '+220'],
        'GN' => ['name' => 'Guinea', 'dial' => '+224'],
        'GQ' => ['name' => 'Equatorial Guinea', 'dial' => '+240'],
        'GR' => ['name' => 'Greece', 'dial' => '+30'],
        'GT' => ['name' => 'Guatemala', 'dial' => '+502'],
        'GY' => ['name' => 'Guyana', 'dial' => '+592'],
        'HK' => ['name' => 'Hong Kong', 'dial' => '+852'],
        'HN' => ['name' => 'Honduras', 'dial' => '+504'],
        'HR' => ['name' => 'Croatia', 'dial' => '+385'],
        'HT' => ['name' => 'Haiti', 'dial' => '+509'],
        'HU' => ['name' => 'Hungary', 'dial' => '+36'],
        'ID' => ['name' => 'Indonesia', 'dial' => '+62'],
        'IE' => ['name' => 'Ireland', 'dial' => '+353'],
        'IL' => ['name' => 'Israel', 'dial' => '+972'],
        'IN' => ['name' => 'India', 'dial' => '+91'],
        'IQ' => ['name' => 'Iraq', 'dial' => '+964'],
        'IR' => ['name' => 'Iran', 'dial' => '+98'],
        'IS' => ['name' => 'Iceland', 'dial' => '+354'],
        'IT' => ['name' => 'Italy', 'dial' => '+39'],
        'JM' => ['name' => 'Jamaica', 'dial' => '+1876'],
        'JO' => ['name' => 'Jordan', 'dial' => '+962'],
        'JP' => ['name' => 'Japan', 'dial' => '+81'],
        'KE' => ['name' => 'Kenya', 'dial' => '+254'],
        'KG' => ['name' => 'Kyrgyzstan', 'dial' => '+996'],
        'KH' => ['name' => 'Cambodia', 'dial' => '+855'],
        'KM' => ['name' => 'Comoros', 'dial' => '+269'],
        'KN' => ['name' => 'Saint Kitts and Nevis', 'dial' => '+1869'],
        'KR' => ['name' => 'South Korea', 'dial' => '+82'],
        'KW' => ['name' => 'Kuwait', 'dial' => '+965'],
        'KZ' => ['name' => 'Kazakhstan', 'dial' => '+7'],
        'LA' => ['name' => 'Laos', 'dial' => '+856'],
        'LB' => ['name' => 'Lebanon', 'dial' => '+961'],
        'LC' => ['name' => 'Saint Lucia', 'dial' => '+1758'],
        'LK' => ['name' => 'Sri Lanka', 'dial' => '+94'],
        'LR' => ['name' => 'Liberia', 'dial' => '+231'],
        'LS' => ['name' => 'Lesotho', 'dial' => '+266'],
        'LT' => ['name' => 'Lithuania', 'dial' => '+370'],
        'LU' => ['name' => 'Luxembourg', 'dial' => '+352'],
        'LV' => ['name' => 'Latvia', 'dial' => '+371'],
        'LY' => ['name' => 'Libya', 'dial' => '+218'],
        'MA' => ['name' => 'Morocco', 'dial' => '+212'],
        'MD' => ['name' => 'Moldova', 'dial' => '+373'],
        'ME' => ['name' => 'Montenegro', 'dial' => '+382'],
        'MG' => ['name' => 'Madagascar', 'dial' => '+261'],
        'MK' => ['name' => 'North Macedonia', 'dial' => '+389'],
        'ML' => ['name' => 'Mali', 'dial' => '+223'],
        'MM' => ['name' => 'Myanmar', 'dial' => '+95'],
        'MN' => ['name' => 'Mongolia', 'dial' => '+976'],
        'MO' => ['name' => 'Macau', 'dial' => '+853'],
        'MR' => ['name' => 'Mauritania', 'dial' => '+222'],
        'MU' => ['name' => 'Mauritius', 'dial' => '+230'],
        'MW' => ['name' => 'Malawi', 'dial' => '+265'],
        'MX' => ['name' => 'Mexico', 'dial' => '+52'],
        'MY' => ['name' => 'Malaysia', 'dial' => '+60'],
        'MZ' => ['name' => 'Mozambique', 'dial' => '+258'],
        'NA' => ['name' => 'Namibia', 'dial' => '+264'],
        'NC' => ['name' => 'New Caledonia', 'dial' => '+687'],
        'NG' => ['name' => 'Nigeria', 'dial' => '+234'],
        'NI' => ['name' => 'Nicaragua', 'dial' => '+505'],
        'NL' => ['name' => 'Netherlands', 'dial' => '+31'],
        'NO' => ['name' => 'Norway', 'dial' => '+47'],
        'NP' => ['name' => 'Nepal', 'dial' => '+977'],
        'NZ' => ['name' => 'New Zealand', 'dial' => '+64'],
        'OM' => ['name' => 'Oman', 'dial' => '+968'],
        'PA' => ['name' => 'Panama', 'dial' => '+507'],
        'PE' => ['name' => 'Peru', 'dial' => '+51'],
        'PG' => ['name' => 'Papua New Guinea', 'dial' => '+675'],
        'PH' => ['name' => 'Philippines', 'dial' => '+63'],
        'PK' => ['name' => 'Pakistan', 'dial' => '+92'],
        'PL' => ['name' => 'Poland', 'dial' => '+48'],
        'PR' => ['name' => 'Puerto Rico', 'dial' => '+1787'],
        'PT' => ['name' => 'Portugal', 'dial' => '+351'],
        'PY' => ['name' => 'Paraguay', 'dial' => '+595'],
        'QA' => ['name' => 'Qatar', 'dial' => '+974'],
        'RO' => ['name' => 'Romania', 'dial' => '+40'],
        'RS' => ['name' => 'Serbia', 'dial' => '+381'],
        'RU' => ['name' => 'Russia', 'dial' => '+7'],
        'RW' => ['name' => 'Rwanda', 'dial' => '+250'],
        'SA' => ['name' => 'Saudi Arabia', 'dial' => '+966'],
        'SC' => ['name' => 'Seychelles', 'dial' => '+248'],
        'SE' => ['name' => 'Sweden', 'dial' => '+46'],
        'SG' => ['name' => 'Singapore', 'dial' => '+65'],
        'SI' => ['name' => 'Slovenia', 'dial' => '+386'],
        'SK' => ['name' => 'Slovakia', 'dial' => '+421'],
        'SL' => ['name' => 'Sierra Leone', 'dial' => '+232'],
        'SN' => ['name' => 'Senegal', 'dial' => '+221'],
        'SO' => ['name' => 'Somalia', 'dial' => '+252'],
        'SR' => ['name' => 'Suriname', 'dial' => '+597'],
        'ST' => ['name' => 'Sao Tome and Principe', 'dial' => '+239'],
        'SV' => ['name' => 'El Salvador', 'dial' => '+503'],
        'SZ' => ['name' => 'Swaziland', 'dial' => '+268'],
        'TG' => ['name' => 'Togo', 'dial' => '+228'],
        'TH' => ['name' => 'Thailand', 'dial' => '+66'],
        'TJ' => ['name' => 'Tajikistan', 'dial' => '+992'],
        'TL' => ['name' => 'Timor-Leste', 'dial' => '+670'],
        'TN' => ['name' => 'Tunisia', 'dial' => '+216'],
        'TR' => ['name' => 'Turkey', 'dial' => '+90'],
        'TT' => ['name' => 'Trinidad and Tobago', 'dial' => '+1868'],
        'TW' => ['name' => 'Taiwan', 'dial' => '+886'],
        'TZ' => ['name' => 'Tanzania', 'dial' => '+255'],
        'UA' => ['name' => 'Ukraine', 'dial' => '+380'],
        'UG' => ['name' => 'Uganda', 'dial' => '+256'],
        'US' => ['name' => 'United States', 'dial' => '+1'],
        'UY' => ['name' => 'Uruguay', 'dial' => '+598'],
        'UZ' => ['name' => 'Uzbekistan', 'dial' => '+998'],
        'VC' => ['name' => 'Saint Vincent and the Grenadines', 'dial' => '+1784'],
        'VE' => ['name' => 'Venezuela', 'dial' => '+58'],
        'VN' => ['name' => 'Vietnam', 'dial' => '+84'],
        'ZA' => ['name' => 'South Africa', 'dial' => '+27'],
        'ZM' => ['name' => 'Zambia', 'dial' => '+260'],
        'ZW' => ['name' => 'Zimbabwe', 'dial' => '+263'],
    ];

    /**
     * Get dial code for a given ISO country code
     */
    public static function getCountryDialCode(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        return self::$countryMetadata[$code]['dial'] ?? '';
    }

    /**
     * Render a crisp, retina-ready country flag badge
     * 
     * @param string $countryCode 2-letter ISO code (e.g. US, GB, NG)
     * @param string $countryName Optional country label
     * @param string $size 'xs' (16px), 'sm' (20px), 'md' (24px), 'lg' (32px)
     * @param bool $withDialCode Whether to render the +code badge
     */
    public static function renderCountryFlag(
        string $countryCode,
        string $countryName = '',
        string $size = 'md',
        bool $withDialCode = false
    ): string {
        $code = strtoupper(trim($countryCode));
        $lower = strtolower($code);
        $name = $countryName ?: (self::$countryMetadata[$code]['name'] ?? $code);
        $dial = self::$countryMetadata[$code]['dial'] ?? '';

        $sizeMap = [
            'xs' => ['w' => 18, 'h' => 12, 'class' => 'h-3 w-4.5 text-[10px]'],
            'sm' => ['w' => 24, 'h' => 16, 'class' => 'h-4 w-6 text-xs'],
            'md' => ['w' => 28, 'h' => 19, 'class' => 'h-5 w-7 text-xs'],
            'lg' => ['w' => 36, 'h' => 24, 'class' => 'h-6 w-9 text-sm'],
            'xl' => ['w' => 48, 'h' => 32, 'class' => 'h-8 w-12 text-base']
        ];
        $dim = $sizeMap[$size] ?? $sizeMap['md'];

        // FlagCDN provides ultra-crisp, high-performance Cloudflare-cached flag images
        $flagUrl = "https://flagcdn.com/w40/{$lower}.png";
        $flagUrl2x = "https://flagcdn.com/w80/{$lower}.png 2x";

        $html = '<span class="inline-flex items-center gap-1.5 align-middle">';
        $html .= '<img src="' . htmlspecialchars($flagUrl, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'srcset="' . htmlspecialchars($flagUrl2x, ENT_QUOTES, 'UTF-8') . '" ';
        $html .= 'width="' . (int)$dim['w'] . '" height="' . (int)$dim['h'] . '" ';
        $html .= 'alt="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' flag" ';
        $html .= 'class="rounded-sm object-cover shadow-xs border border-slate-200/60 inline-block align-middle flex-shrink-0" ';
        $html .= 'loading="lazy" ';
        $html .= 'onerror="this.style.display=\'none\';" />';

        if ($withDialCode && $dial !== '') {
            $html .= '<span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-mono font-bold text-[10px]">' . htmlspecialchars($dial, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $html .= '</span>';
        return $html;
    }

    /**
     * Get brand styling metadata and colors
     */
    public static function getServiceBrandMeta(string $serviceCode): array
    {
        $code = strtolower(trim($serviceCode));

        return match ($code) {
            'whatsapp' => [
                'name' => 'WhatsApp',
                'color' => '#25D366',
                'bg_light' => '#E7F8EE',
                'border_color' => '#A7F3D0',
                'text_color' => '#065F46',
                'tagline' => 'WhatsApp Verification'
            ],
            'telegram' => [
                'name' => 'Telegram',
                'color' => '#229ED9',
                'bg_light' => '#EBF7FD',
                'border_color' => '#BAE6FD',
                'text_color' => '#0369A1',
                'tagline' => 'Telegram Verification'
            ],
            'facebook' => [
                'name' => 'Facebook / Meta',
                'color' => '#1877F2',
                'bg_light' => '#EDF4FE',
                'border_color' => '#BFDBFE',
                'text_color' => '#1E40AF',
                'tagline' => 'Facebook / Meta SMS'
            ],
            'instagram' => [
                'name' => 'Instagram / Threads',
                'color' => '#E4405F',
                'bg_light' => '#FDF1F3',
                'border_color' => '#FECDD3',
                'text_color' => '#9F1239',
                'tagline' => 'Instagram & Threads'
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'color' => '#000000',
                'bg_light' => '#F3F4F6',
                'border_color' => '#E5E7EB',
                'text_color' => '#111827',
                'tagline' => 'TikTok Verification'
            ],
            'google' => [
                'name' => 'Google / YouTube',
                'color' => '#4285F4',
                'bg_light' => '#EEF4FF',
                'border_color' => '#C7D2FE',
                'text_color' => '#3730A3',
                'tagline' => 'Google & YouTube'
            ],
            'twitter', 'x' => [
                'name' => 'X (Twitter)',
                'color' => '#000000',
                'bg_light' => '#F4F4F5',
                'border_color' => '#E4E4E7',
                'text_color' => '#18181B',
                'tagline' => 'X (Twitter) SMS'
            ],
            'openai', 'chatgpt' => [
                'name' => 'OpenAI / ChatGPT',
                'color' => '#10A37F',
                'bg_light' => '#EBF9F5',
                'border_color' => '#A7F3D0',
                'text_color' => '#065F46',
                'tagline' => 'ChatGPT & OpenAI'
            ],
            'microsoft' => [
                'name' => 'Microsoft / Outlook',
                'color' => '#00A4EF',
                'bg_light' => '#EBF7FD',
                'border_color' => '#BAE6FD',
                'text_color' => '#0284C7',
                'tagline' => 'Outlook, Hotmail, Xbox'
            ],
            'apple' => [
                'name' => 'Apple',
                'color' => '#000000',
                'bg_light' => '#F3F4F6',
                'border_color' => '#E5E7EB',
                'text_color' => '#1F2937',
                'tagline' => 'Apple ID & iCloud'
            ],
            'amazon' => [
                'name' => 'Amazon',
                'color' => '#FF9900',
                'bg_light' => '#FFF7ED',
                'border_color' => '#FED7AA',
                'text_color' => '#9A3412',
                'tagline' => 'Amazon & Prime'
            ],
            'netflix' => [
                'name' => 'Netflix',
                'color' => '#E50914',
                'bg_light' => '#FEF2F2',
                'border_color' => '#FECACA',
                'text_color' => '#991B1B',
                'tagline' => 'Netflix Streaming'
            ],
            'steam' => [
                'name' => 'Steam',
                'color' => '#171A21',
                'bg_light' => '#F1F5F9',
                'border_color' => '#E2E8F0',
                'text_color' => '#0F172A',
                'tagline' => 'Steam Gaming Platform'
            ],
            'discord' => [
                'name' => 'Discord',
                'color' => '#5865F2',
                'bg_light' => '#EEF0FD',
                'border_color' => '#C7D2FE',
                'text_color' => '#3730A3',
                'tagline' => 'Discord Community'
            ],
            'snapchat' => [
                'name' => 'Snapchat',
                'color' => '#FFFC00',
                'bg_light' => '#FEFCE8',
                'border_color' => '#FEF08A',
                'text_color' => '#854D0E',
                'tagline' => 'Snapchat Verification'
            ],
            default => [
                'name' => ucfirst($code),
                'color' => '#635BDB',
                'bg_light' => '#F5F3FF',
                'border_color' => '#DDD6FE',
                'text_color' => '#4338CA',
                'tagline' => 'SMS Verification'
            ]
        };
    }

    /**
     * Render authentic, crisp SVG brand logo
     * 
     * @param string $serviceCode The service identifier
     * @param string $size 'sm' (20px), 'md' (28px), 'lg' (36px), 'xl' (48px)
     * @param bool $withContainer Wrap in a styled rounded container
     */
    public static function renderServiceLogo(
        string $serviceCode,
        string $size = 'md',
        bool $withContainer = true
    ): string {
        $code = strtolower(trim($serviceCode));
        $meta = self::getServiceBrandMeta($code);

        $sizeMap = [
            'sm' => ['box' => 'h-7 w-7 rounded-lg', 'svg' => 'h-4 w-4'],
            'md' => ['box' => 'h-10 w-10 rounded-xl', 'svg' => 'h-5 w-5'],
            'lg' => ['box' => 'h-12 w-12 rounded-2xl', 'svg' => 'h-6 w-6'],
            'xl' => ['box' => 'h-16 w-16 rounded-2xl', 'svg' => 'h-8 w-8']
        ];
        $dim = $sizeMap[$size] ?? $sizeMap['md'];
        $svgClass = $dim['svg'];

        $svg = match ($code) {
            'whatsapp' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="11" fill="#25D366"/>
                <path fill-rule="evenodd" clip-rule="evenodd" d="M17.5 14.5c-.3-.1-1.7-.8-1.9-.9-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.1-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6.1-.1.3-.3.4-.5.1-.2.2-.3.3-.5.1-.2 0-.4-.1-.5-.1-.1-.7-1.7-1-2.3-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1.1 1.1-1.1 2.6s1.1 3 1.3 3.2c.2.2 2.2 3.4 5.3 4.8.7.3 1.3.5 1.8.7.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2.1-1.5.3-.7.3-1.3.2-1.5-.1-.1-.3-.2-.6-.3z" fill="#FFFFFF"/>
                <path d="M12 4.5A7.5 7.5 0 005.5 15.7L4.7 19l3.4-.9A7.5 7.5 0 1012 4.5z" stroke="#FFFFFF" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>',

            'telegram' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="11" fill="#229ED9"/>
                <path d="M17.5 7.3L5.8 11.8c-.8.3-.8.8-.1 1l3 1 7-4.4c.3-.2.6-.1.4.1l-5.7 5.1-.2 3.1c.3 0 .4-.1.6-.3l1.5-1.5 3.1 2.3c.6.3 1 .2 1.2-.5l2-9.6c.2-.9-.3-1.3-.9-.9z" fill="#FFFFFF"/>
            </svg>',

            'facebook' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="11" fill="#1877F2"/>
                <path d="M13.8 22V13.8h2.8l.4-3.2h-3.2V8.5c0-.9.3-1.6 1.6-1.6h1.7V4.1c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.3v2.3H7.7v3.2h2.8V22h3.3z" fill="#FFFFFF"/>
            </svg>',

            'instagram' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="igG1" cx="30%" cy="107%" r="150%">
                        <stop offset="0%" stop-color="#fdf497"/>
                        <stop offset="5%" stop-color="#fdf497"/>
                        <stop offset="45%" stop-color="#fd5949"/>
                        <stop offset="60%" stop-color="#d6249f"/>
                        <stop offset="90%" stop-color="#285AEB"/>
                    </radialGradient>
                </defs>
                <rect x="1" y="1" width="22" height="22" rx="7" fill="url(#igG1)"/>
                <rect x="5.5" y="5.5" width="13" height="13" rx="3.5" stroke="#FFFFFF" stroke-width="1.8"/>
                <circle cx="12" cy="12" r="3.2" stroke="#FFFFFF" stroke-width="1.8"/>
                <circle cx="15.8" cy="8.2" r="1" fill="#FFFFFF"/>
            </svg>',

            'tiktok' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#000000"/>
                <path d="M16.5 7.8c-1.1-.1-2.1-.7-2.7-1.6v6.9c0 2.6-2.1 4.7-4.7 4.7S4.4 15.7 4.4 13.1c0-2.4 1.8-4.4 4.1-4.7v2.4c-1.1.2-1.9 1.1-1.9 2.3 0 1.3 1 2.3 2.3 2.3s2.3-1 2.3-2.3V2.5h2.4c.2 1.8 1.6 3.2 3.4 3.4v1.9z" fill="#00F2FE"/>
                <path d="M15.8 7.2c-1.1-.1-2.1-.7-2.7-1.6v6.9c0 2.6-2.1 4.7-4.7 4.7-1.1 0-2.1-.4-2.9-1 .8.8 1.9 1.3 3.1 1.3 2.6 0 4.7-2.1 4.7-4.7V6.5c.7.9 1.7 1.5 2.8 1.6V7.2z" fill="#FE2C55"/>
                <path d="M15.5 6.8c-1.1-.1-2.1-.7-2.7-1.6v6.9c0 2.6-2.1 4.7-4.7 4.7-1.2 0-2.3-.5-3.1-1.2-.6-.6-1-1.5-1-2.5 0-1.9 1.5-3.5 3.4-3.7v1.8c-.8.2-1.4.9-1.4 1.8 0 1 .8 1.8 1.8 1.8s1.8-.8 1.8-1.8V2h2.2c.2 1.8 1.6 3.2 3.4 3.4v1.4z" fill="#FFFFFF"/>
            </svg>',

            'google' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#FFFFFF" stroke="#E5E7EB" stroke-width="1"/>
                <path d="M18.8 12.2c0-.6 0-1.2-.1-1.7H12v3.3h3.8c-.2 1-.7 1.8-1.5 2.4v2h2.4c1.4-1.3 2.1-3.2 2.1-6z" fill="#4285F4"/>
                <path d="M12 19.1c1.9 0 3.5-.6 4.7-1.7l-2.4-2c-.6.4-1.4.7-2.3.7-1.8 0-3.3-1.2-3.8-2.8H5.7v2c1.2 2.4 3.7 3.8 6.3 3.8z" fill="#34A853"/>
                <path d="M8.2 13.3c-.1-.4-.2-.9-.2-1.3 0-.5.1-.9.2-1.3V8.7H5.7C5.2 9.7 5 10.8 5 12s.2 2.3.7 3.3l2.5-2z" fill="#FBBC05"/>
                <path d="M12 7.7c1 0 1.9.4 2.6 1l2-2C15.4 5.6 13.8 5 12 5 9.4 5 6.9 6.4 5.7 8.7l2.5 2c.5-1.6 2-3 3.8-3z" fill="#EA4335"/>
            </svg>',

            'twitter', 'x' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#000000"/>
                <path d="M14.2 10.4L19.2 4.5h-1.2l-4.3 5.1L10.3 4.5H6.2l5.3 7.8L6.2 19.5h1.2l4.6-5.5 3.6 5.5h4.1l-5.5-9.1zm-1.6 1.9l-.5-.8-4.2-6.1h1.8l3.4 4.9.5.8 4.4 6.3h-1.8l-3.6-5.1z" fill="#FFFFFF"/>
            </svg>',

            'openai', 'chatgpt' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#10A37F"/>
                <path d="M18.8 10.2a3.8 3.8 0 00-.3-2.9 3.9 3.9 0 00-3.3-2 3.8 3.8 0 00-1-.1v1.6c.4.1.8.2 1.1.4.8.5 1.4 1.3 1.6 2.2l.2 1.3 1.1.7a2.2 2.2 0 011 2.3 2.3 2.3 0 01-1.3 1.8v1.6a3.8 3.8 0 001.9-2.9 3.8 3.8 0 00-1-6.1zM8.3 17.6a2.2 2.2 0 01-1-2.3 2.3 2.3 0 011.3-1.8v-1.6a3.8 3.8 0 00-1.9 2.9 3.8 3.8 0 001.2 6.1 3.8 3.8 0 002.9.3 3.9 3.9 0 002-3.3v-1.6l-1.1-.4a3.8 3.8 0 01-1.6-2.2l-.2-1.3-1.1-.7a2.2 2.2 0 01-.5 5.9zm4.8-1.5l1.6-.9v-3.7l-3.2-1.9-3.2 1.9v3.7l3.2 1.9 1.6-.9zm-3.2-5.1l2.1-1.2 2.1 1.2v2.4l-2.1 1.2-2.1-1.2v-2.4z" fill="#FFFFFF"/>
            </svg>',

            'microsoft' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#FFFFFF" stroke="#E5E7EB" stroke-width="1"/>
                <rect x="5.5" y="5.5" width="5.8" height="5.8" fill="#F25022"/>
                <rect x="12.7" y="5.5" width="5.8" height="5.8" fill="#7FBA00"/>
                <rect x="5.5" y="12.7" width="5.8" height="5.8" fill="#00A4EF"/>
                <rect x="12.7" y="12.7" width="5.8" height="5.8" fill="#FFB900"/>
            </svg>',

            'apple' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#000000"/>
                <path d="M14.8 12.3c0-2.1 1.7-3.1 1.8-3.2-1-1.4-2.5-1.6-3-1.6-1.3-.1-2.5.8-3.1.8-.7 0-1.6-.7-2.6-.7-1.4 0-2.6.8-3.3 2-1.5 2.5-.4 6.3 1 8.4.7 1 1.5 2.1 2.6 2.1 1 0 1.5-.7 2.7-.7s1.6.7 2.7.7c1.1 0 1.8-1 2.5-2 .8-1.2 1.1-2.4 1.1-2.5-.1 0-2.4-.9-2.4-3.3zm-1.8-5.7c.6-.7 1-1.7.9-2.6-.8 0-1.9.6-2.5 1.3-.5.6-.9 1.6-.8 2.6.9.1 1.8-.5 2.4-1.3z" fill="#FFFFFF"/>
            </svg>',

            'amazon' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#131921"/>
                <path d="M15.4 13.9c-.3.2-.8.3-1.2.3-.7 0-1.3-.3-1.7-.8-.4.5-1 .8-1.7.8-.9 0-1.7-.6-1.7-1.8 0-.9.5-1.5 1.2-1.8.6-.3 1.4-.3 2.1-.4v-.1c0-.2 0-.5-.1-.7-.1-.2-.3-.2-.5-.2-.4 0-.7.2-.7.6l-1.6-.2c.1-1.4 1.5-1.8 2.7-1.8.6 0 1.5.2 2 .7.6.6.6 1.4.6 2.2v2c0 .6.3.9.5 1.2l-1.1 1.3zm-3.1-2.2c-.5 0-1.1.1-1.1.8 0 .4.2.6.5.6.2 0 .5-.1.6-.4.2-.3.2-.6.2-1v-.3c-.1.2-.2.3-.2.3zm5.1 3.9c-2.5 1.9-6.2 2.9-9.3 2.9-.7 0-1.4 0-2-.1-.1 0-.2-.2 0-.2 3.6-1.2 7.5-1 10.8.5.3.1.9.5 1 .6.1.1 0 .2-.4.6l-.1-.3z" fill="#FFFFFF"/>
                <path d="M17.4 16.5c-.3.2-.8.1-.9-.2-.1-.2-.2-.5-.1-.7.1-.2.2-.4.4-.5.3-.2.7-.2.9.1.2.2.3.5.1.7-.1.3-.2.5-.4.6z" fill="#FF9900"/>
            </svg>',

            'netflix' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#000000"/>
                <path d="M7.8 4v16l2.9-1V4H7.8z" fill="#E50914"/>
                <path d="M13.3 4v16l2.9-1V4h-2.9z" fill="#E50914"/>
                <path d="M7.8 4l8.4 15.3V4h-2.9v10.2L9.4 4H7.8z" fill="#B81D24"/>
            </svg>',

            'steam' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#171A21"/>
                <path d="M12 3a9 9 0 00-9 8.7c0 .8.1 1.6.4 2.3l4.5 1.9a3.2 3.2 0 011.6-.4c.3 0 .7 0 1 .2l2.3-3.3a4 4 0 01-.3-1.6 4.2 4.2 0 114.2 4.2c-.5 0-1-.1-1.5-.3l-3.3 2.4c.1.3.2.7.2 1a3.2 3.2 0 01-5.4 2.3L4.4 19.3A9 9 0 1012 3zm4.2 6.8a2.3 2.3 0 100 4.6 2.3 2.3 0 000-4.6zm-5.7 8.3a1.8 1.8 0 100-3.6 1.8 1.8 0 000 3.6z" fill="#FFFFFF"/>
            </svg>',

            'discord' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="11" fill="#5865F2"/>
                <path d="M16.5 7.5s-1-.6-2.2-.8l-.1.2c1.2.3 1.8.8 1.8.8-1.1-.6-2.2-.9-3.5-.9s-2.4.3-3.5.9c0 0 .6-.5 1.8-.8l-.1-.2c-1.2.2-2.2.8-2.2.8-1.5 2.2-1.9 4.3-1.9 6.4 1.2.9 2.4.9 2.4.9l.5-.7c-.8-.2-1.2-.7-1.2-.7s.1.1.3.1c1.3.7 2.6 1 4 1s2.7-.3 4-1c.2 0 .3-.1.3-.1s-.4.5-1.2.7l.5.7s1.2 0 2.4-.9c0-2.1-.4-4.2-1.9-6.4zm-6.2 5.5c-.7 0-1.2-.6-1.2-1.3s.5-1.3 1.2-1.3c.7 0 1.2.6 1.2 1.3s-.5 1.3-1.2 1.3zm4.4 0c-.7 0-1.2-.6-1.2-1.3s.5-1.3 1.2-1.3c.7 0 1.2.6 1.2 1.3s-.5 1.3-1.2 1.3z" fill="#FFFFFF"/>
            </svg>',

            'snapchat' => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="1" y="1" width="22" height="22" rx="6" fill="#FFFC00"/>
                <path d="M12 5.5c-2.3 0-3.6 1.7-3.6 3.6 0 .5.2 1.4.3 1.6.1.2 0 .4-.2.5-.3.1-.9.3-1.1.7-.2.4.2.7.4.8.4.1.7.3.7.6 0 .4-.5.9-1.2 1-.4 0-.6.3-.6.5s.4.5 1 .7c.6.2 1 .1 1.2.5.2.4-.3 1.1-.9 1.6-.4.4-.3.7-.1.9.3.2 1.5.4 2.8-.4.4-.2.8-.2 1.1 0 1.3.8 2.5.6 2.8.4.2-.2.3-.5-.1-.9-.6-.5-1.1-1.2-.9-1.6.2-.4.6-.3 1.2-.5.6-.2 1-.5 1-.7s-.2-.5-.6-.5c-.7-.1-1.2-.6-1.2-1 0-.3.3-.5.7-.6.2-.1.6-.4.4-.8-.2-.4-.8-.6-1.1-.7-.2-.1-.3-.3-.2-.5.1-.2.3-1.1.3-1.6 0-1.9-1.3-3.6-3.6-3.6z" fill="#000000"/>
            </svg>',

            default => '<svg class="' . $svgClass . '" viewBox="0 0 24 24" fill="none" stroke="' . htmlspecialchars($meta['color']) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                <circle cx="12" cy="10" r="1"></circle>
                <circle cx="8" cy="10" r="1"></circle>
                <circle cx="16" cy="10" r="1"></circle>
            </svg>'
        };

        if (!$withContainer) {
            return $svg;
        }

        return '<div class="inline-flex items-center justify-center flex-shrink-0 shadow-xs ' . $dim['box'] . '" style="background-color: ' . htmlspecialchars($meta['bg_light']) . '; border: 1px solid ' . htmlspecialchars($meta['border_color']) . ';">' . $svg . '</div>';
    }
}
