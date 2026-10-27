<?php
/**
 * IDN tables (second level) for the Indian IDN TLDs of NIXI (registry.in), keyed by the A-label of the TLD.
 *
 * Each regex follows the grammar (ABNF) and restriction rules of NIXI's IDN language table for that
 * TLD and uses the code points of its nomenclatural table. Used by the "India (NIXI)" option when
 * a TLD is created.
 */

return [
    'xn--h2breg3eve' => [
        'tld'       => 'भारतम्',
        'name'      => 'Devanagari (India, Sanskrit)',
        'script'    => 'deva',
        'languages' => 'Sanskrit',
        'regex'     => '/^(?!-)(?!.*([\x{0915}-\x{0928}\x{092A}-\x{0930}\x{0932}-\x{0933}\x{0935}-\x{0939}])[\x{094D}]\1[\x{094D}]\1)(?:-?(?:[0-9]|(?:[\x{0905}-\x{090C}\x{090F}-\x{0910}\x{0913}-\x{0914}\x{0960}-\x{0961}][\x{0901}-\x{0903}]?|[\x{0915}-\x{0928}\x{092A}-\x{0930}\x{0932}-\x{0933}\x{0935}-\x{0939}](?:[\x{094D}][\x{0915}-\x{0928}\x{092A}-\x{0930}\x{0932}-\x{0933}\x{0935}-\x{0939}]){0,4}(?:[\x{094D}](?=$|-|[0-9])|(?:[\x{0901}-\x{0903}]|[\x{093E}-\x{0944}\x{0947}-\x{0948}\x{094B}-\x{094C}\x{0962}-\x{0963}][\x{0901}-\x{0903}]?)?))[\x{093D}]?))+$/u',
    ],
    'xn--gecrj9c' => [
        'tld'       => 'ભારત',
        'name'      => 'Gujarati (India)',
        'script'    => 'gujr',
        'languages' => 'Gujarati',
        'regex'     => '/^(?!-)(?!.*([\x{0A95}-\x{0AA8}\x{0AAA}-\x{0AB0}\x{0AB2}-\x{0AB3}\x{0AB5}-\x{0AB9}])[\x{0ACD}]\1[\x{0ACD}]\1)(?:-?(?:[0-9]|(?:[\x{0A85}-\x{0A8B}\x{0A8D}\x{0A8F}-\x{0A91}\x{0A93}-\x{0A94}][\x{0A81}-\x{0A83}]?|[\x{0A95}-\x{0AA8}\x{0AAA}-\x{0AB0}\x{0AB2}-\x{0AB3}\x{0AB5}-\x{0AB9}](?:[\x{0ACD}][\x{0A95}-\x{0AA8}\x{0AAA}-\x{0AB0}\x{0AB2}-\x{0AB3}\x{0AB5}-\x{0AB9}]){0,3}(?:[\x{0ACD}](?=$|-|[0-9]|[\x{0ABD}])|(?:[\x{0A81}-\x{0A83}]|[\x{0ABE}-\x{0AC3}\x{0AC5}\x{0AC7}-\x{0AC9}\x{0ACB}-\x{0ACC}][\x{0A81}-\x{0A83}]?)?))[\x{0ABD}]?))+$/u',
    ],
    'xn--2scrj9c' => [
        'tld'       => 'ಭಾರತ',
        'name'      => 'Kannada (India)',
        'script'    => 'knda',
        'languages' => 'Kannada',
        'regex'     => '/^(?!-)(?!.*([\x{0C95}-\x{0CA8}\x{0CAA}-\x{0CB3}\x{0CB5}-\x{0CB9}])[\x{0CCD}]\1[\x{0CCD}]\1)(?:-?(?:[0-9]|(?:[\x{0C85}-\x{0C8C}\x{0C8E}-\x{0C90}\x{0C92}-\x{0C94}][\x{0C82}-\x{0C83}]?|[\x{0C95}-\x{0CA8}\x{0CAA}-\x{0CB3}\x{0CB5}-\x{0CB9}](?:[\x{0CCD}][\x{0C95}-\x{0CA8}\x{0CAA}-\x{0CB3}\x{0CB5}-\x{0CB9}]){0,3}(?:[\x{0CCD}](?=$|-|[0-9])|(?:[\x{0C82}-\x{0C83}]|[\x{0CBE}-\x{0CC3}\x{0CC6}-\x{0CC8}\x{0CCA}-\x{0CCC}\x{0CE2}][\x{0C82}-\x{0C83}]?)?))[\x{0CBD}]?))+$/u',
    ],
    'xn--fpcrj9c3d' => [
        'tld'       => 'భారత్',
        'name'      => 'Telugu (India)',
        'script'    => 'telu',
        'languages' => 'Telugu',
        'regex'     => '/^(?!-)(?!.*([\x{0C15}-\x{0C28}\x{0C2A}-\x{0C33}\x{0C35}-\x{0C39}\x{0C58}-\x{0C59}])[\x{0C4D}]\1[\x{0C4D}]\1)(?:-?(?:[0-9]|(?:[\x{0C05}-\x{0C0B}\x{0C0E}-\x{0C10}\x{0C12}-\x{0C14}\x{0C60}][\x{0C01}-\x{0C03}]?|[\x{0C15}-\x{0C28}\x{0C2A}-\x{0C33}\x{0C35}-\x{0C39}\x{0C58}-\x{0C59}](?:[\x{0C4D}][\x{0C15}-\x{0C28}\x{0C2A}-\x{0C33}\x{0C35}-\x{0C39}\x{0C58}-\x{0C59}]){0,2}(?:[\x{0C4D}](?=$|-|[0-9]|[\x{0C3D}])|(?:[\x{0C01}-\x{0C03}]|[\x{0C3E}-\x{0C44}\x{0C46}-\x{0C48}\x{0C4A}-\x{0C4C}][\x{0C01}-\x{0C03}]?)?))[\x{0C3D}]?))+$/u',
    ],
    'xn--xkc2dl3a5ee0h' => [
        'tld'       => 'இந்தியா',
        'name'      => 'Tamil (India)',
        'script'    => 'taml',
        'languages' => 'Tamil',
        'regex'     => '/^(?!-)(?!.*([\x{0B95}\x{0B99}-\x{0B9A}\x{0B9C}\x{0B9E}-\x{0B9F}\x{0BA3}-\x{0BA4}\x{0BA8}-\x{0BAA}\x{0BAE}-\x{0BB9}])[\x{0BCD}]\1[\x{0BCD}]\1)(?:-?(?:[0-9]|(?:[\x{0B85}-\x{0B8A}\x{0B8E}-\x{0B90}\x{0B92}-\x{0B94}][\x{0B83}]?|[\x{0B95}\x{0B99}-\x{0B9A}\x{0B9C}\x{0B9E}-\x{0B9F}\x{0BA3}-\x{0BA4}\x{0BA8}-\x{0BAA}\x{0BAE}-\x{0BB9}](?:[\x{0BCD}][\x{0B95}\x{0B99}-\x{0B9A}\x{0B9C}\x{0B9E}-\x{0B9F}\x{0BA3}-\x{0BA4}\x{0BA8}-\x{0BAA}\x{0BAE}-\x{0BB9}]){0,2}(?:[\x{0BCD}](?=$|-|[0-9])|(?:[\x{0B83}]|[\x{0BBE}-\x{0BC2}\x{0BC6}-\x{0BC8}\x{0BCA}-\x{0BCC}])?))))+$/u',
    ],
    'xn--3hcrj9c' => [
        'tld'       => 'ଭାରତ',
        'name'      => 'Oriya (India)',
        'script'    => 'orya',
        'languages' => 'Oriya',
        'regex'     => '/^(?!-)(?!.*([\x{0B15}-\x{0B28}\x{0B2A}-\x{0B30}\x{0B32}-\x{0B33}\x{0B36}-\x{0B39}\x{0B5F}\x{0B71}])[\x{0B4D}]\1[\x{0B4D}]\1)(?:-?(?:[0-9]|(?:[\x{0B05}-\x{0B0B}\x{0B0F}-\x{0B10}\x{0B13}-\x{0B14}][\x{0B01}-\x{0B03}]?|[\x{0B15}-\x{0B28}\x{0B2A}-\x{0B30}\x{0B32}-\x{0B33}\x{0B36}-\x{0B39}\x{0B5F}\x{0B71}][\x{0B3C}]?(?:[\x{0B4D}][\x{0B15}-\x{0B28}\x{0B2A}-\x{0B30}\x{0B32}-\x{0B33}\x{0B36}-\x{0B39}\x{0B5F}\x{0B71}][\x{0B3C}]?){0,3}(?:[\x{0B4D}](?=$|-|[0-9])|(?:[\x{0B01}-\x{0B03}]|[\x{0B3E}-\x{0B43}\x{0B47}-\x{0B48}\x{0B4B}-\x{0B4C}][\x{0B01}-\x{0B03}]?)?))[\x{0B3D}]?))+$/u',
    ],
    'xn--rvc1e0am3e' => [
        'tld'       => 'ഭാരതം',
        'name'      => 'Malayalam (India)',
        'script'    => 'mlym',
        'languages' => 'Malayalam',
        'regex'     => '/^(?!-)(?!.*([\x{0D15}-\x{0D28}\x{0D2A}-\x{0D39}])[\x{0D4D}]\1[\x{0D4D}]\1)(?:-?(?:[0-9]|(?:[\x{0D05}-\x{0D0C}\x{0D0E}-\x{0D10}\x{0D12}-\x{0D14}][\x{0D02}-\x{0D03}]?|[\x{0D15}-\x{0D28}\x{0D2A}-\x{0D39}](?:[\x{0D4D}][\x{0D15}-\x{0D28}\x{0D2A}-\x{0D39}]){0,3}(?:[\x{0D4D}](?=$|-|[0-9])|(?:[\x{0D02}-\x{0D03}]|[\x{0D3E}-\x{0D42}\x{0D46}-\x{0D48}\x{0D4A}-\x{0D4B}\x{0D57}][\x{0D02}-\x{0D03}]?)?)|[\x{0D7A}-\x{0D7E}]|[\x{0D7B}][\x{0D4D}][\x{0D31}](?:[\x{0D02}]|[\x{0D4D}]|[\x{0D3E}-\x{0D42}\x{0D46}-\x{0D48}\x{0D4A}-\x{0D4B}\x{0D57}][\x{0D02}]?)?)[\x{0D3D}]?))+$/u',
    ],
];
