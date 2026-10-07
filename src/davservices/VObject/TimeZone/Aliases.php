<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\VObject\TimeZone;

/**
 * The names other calendaring systems give a time zone (R-TZ-01).
 *
 * **A `TZID` is whatever the system that wrote it calls a zone.** RFC 5545
 * §3.2.19 says so plainly: "The parameter MUST be specified on the 'DTSTART',
 * 'DTEND', … properties when either a DATE-TIME or TIME value type is
 * specified and when the value is neither a UTC or a 'floating' time", and
 * about the name itself only this — "The presence of the SOLIDUS character as
 * a prefix, indicates that this 'TZID' represents a unique ID in a globally
 * defined time zone registry (when such registry is defined)."
 *
 * So the memo neither defines the names nor requires them to be IANA's. What
 * arrives in the field is Microsoft's `W. Europe Standard Time` as readily as
 * `Europe/Berlin`, and R-TZ-01 asks for a catalogue that knows both.
 *
 * ## Where the table comes from
 *
 * **The names are Windows' own, read from its registry** — the 143 keys under
 * `HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Time Zones`, which is
 * what every Microsoft calendaring product writes into a `TZID`. Each one's
 * `Display` value names the cities the zone covers and the offset it keeps,
 * so **every line here can be checked against the system that invented it**,
 * and the test does exactly that: the zone this table names has to keep the
 * offset Windows states for it.
 *
 * It is a table rather than a lookup in a library, because there is no
 * library: `IntlTimeZone::getIDForWindowsID()` would answer it, and `intl` is
 * an extension this library works without — the CI has a job that proves it.
 * So the catalogue is source code, as a maintained catalogue has to be.
 *
 * ## What is not here
 *
 * **`X-MICROSOFT-CDO-TZID` is a number, not a name**, and the table that
 * turns it into a zone is Microsoft's old zone *index* — defined in
 * MS-OXOCAL rather than in the registry, which has not carried an `Index`
 * value for many Windows versions. It is therefore the one of R-TZ-01's four
 * inputs {@see Resolver} does not read, and it is named as missing rather
 * than guessed at: an index read wrongly puts an appointment in the wrong
 * country.
 *
 * ## The comparison is exact
 *
 * §3.1 makes names and parameter values case-insensitive, but a `TZID`'s
 * value is neither: "all other property values are case-sensitive, unless
 * otherwise stated". So `w. europe standard time` is not a name this
 * catalogue knows, and a system that writes one has written a `TZID` the
 * memo does not define.
 */
final class Aliases
{
    /**
     * Windows' own zone names, and the IANA zone each one keeps.
     *
     * In the registry's order, which is alphabetical, so that the table can
     * be read beside a dump of it.
     *
     * @var array<string, string>
     */
    private const WINDOWS = [
        'Afghanistan Standard Time' => 'Asia/Kabul',
        'Alaskan Standard Time' => 'America/Anchorage',
        'Alberta Standard Time' => 'America/Edmonton',
        'Aleutian Standard Time' => 'America/Adak',
        'Altai Standard Time' => 'Asia/Barnaul',
        'Arab Standard Time' => 'Asia/Riyadh',
        'Arabian Standard Time' => 'Asia/Dubai',
        'Arabic Standard Time' => 'Asia/Baghdad',
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires',
        'Astrakhan Standard Time' => 'Europe/Astrakhan',
        'Atlantic Standard Time' => 'America/Halifax',
        'AUS Central Standard Time' => 'Australia/Darwin',
        'Aus Central W. Standard Time' => 'Australia/Eucla',
        'AUS Eastern Standard Time' => 'Australia/Sydney',
        'Azerbaijan Standard Time' => 'Asia/Baku',
        'Azores Standard Time' => 'Atlantic/Azores',
        'Bahia Standard Time' => 'America/Bahia',
        'Bangladesh Standard Time' => 'Asia/Dhaka',
        'Belarus Standard Time' => 'Europe/Minsk',
        'Bougainville Standard Time' => 'Pacific/Bougainville',
        'British Columbia Standard Time' => 'America/Vancouver',
        'Canada Central Standard Time' => 'America/Regina',
        'Cape Verde Standard Time' => 'Atlantic/Cape_Verde',
        'Caucasus Standard Time' => 'Asia/Yerevan',
        'Cen. Australia Standard Time' => 'Australia/Adelaide',
        'Central America Standard Time' => 'America/Guatemala',
        'Central Asia Standard Time' => 'Asia/Bishkek',
        'Central Brazilian Standard Time' => 'America/Cuiaba',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'Central Pacific Standard Time' => 'Pacific/Guadalcanal',
        'Central Standard Time' => 'America/Chicago',
        'Central Standard Time (Mexico)' => 'America/Mexico_City',
        'Chatham Islands Standard Time' => 'Pacific/Chatham',
        'China Standard Time' => 'Asia/Shanghai',
        'Cuba Standard Time' => 'America/Havana',
        'Dateline Standard Time' => 'Etc/GMT+12',
        'E. Africa Standard Time' => 'Africa/Nairobi',
        'E. Australia Standard Time' => 'Australia/Brisbane',
        'E. Europe Standard Time' => 'Europe/Chisinau',
        'E. South America Standard Time' => 'America/Sao_Paulo',
        'Easter Island Standard Time' => 'Pacific/Easter',
        'Eastern Standard Time' => 'America/New_York',
        'Eastern Standard Time (Mexico)' => 'America/Cancun',
        'Egypt Standard Time' => 'Africa/Cairo',
        'Ekaterinburg Standard Time' => 'Asia/Yekaterinburg',
        'Fiji Standard Time' => 'Pacific/Fiji',
        'FLE Standard Time' => 'Europe/Kyiv',
        'Georgian Standard Time' => 'Asia/Tbilisi',
        'GMT Standard Time' => 'Europe/London',
        'Greenland Standard Time' => 'America/Nuuk',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'GTB Standard Time' => 'Europe/Bucharest',
        'Haiti Standard Time' => 'America/Port-au-Prince',
        'Hawaiian Standard Time' => 'Pacific/Honolulu',
        'India Standard Time' => 'Asia/Kolkata',
        'Iran Standard Time' => 'Asia/Tehran',
        'Israel Standard Time' => 'Asia/Jerusalem',
        'Jordan Standard Time' => 'Asia/Amman',
        'Kaliningrad Standard Time' => 'Europe/Kaliningrad',
        'Kamchatka Standard Time' => 'Asia/Kamchatka',
        'Korea Standard Time' => 'Asia/Seoul',
        'Libya Standard Time' => 'Africa/Tripoli',
        'Line Islands Standard Time' => 'Pacific/Kiritimati',
        'Lord Howe Standard Time' => 'Australia/Lord_Howe',
        'Magadan Standard Time' => 'Asia/Magadan',
        'Magallanes Standard Time' => 'America/Punta_Arenas',
        'Marquesas Standard Time' => 'Pacific/Marquesas',
        'Mauritius Standard Time' => 'Indian/Mauritius',
        'Mid-Atlantic Standard Time' => 'Etc/GMT+2',
        'Middle East Standard Time' => 'Asia/Beirut',
        'Montevideo Standard Time' => 'America/Montevideo',
        'Morocco Standard Time' => 'Africa/Casablanca',
        'Mountain Standard Time' => 'America/Denver',
        'Mountain Standard Time (Mexico)' => 'America/Mazatlan',
        'Myanmar Standard Time' => 'Asia/Yangon',
        'N. Central Asia Standard Time' => 'Asia/Novosibirsk',
        'Namibia Standard Time' => 'Africa/Windhoek',
        'Nepal Standard Time' => 'Asia/Kathmandu',
        'New Zealand Standard Time' => 'Pacific/Auckland',
        'Newfoundland Standard Time' => 'America/St_Johns',
        'Norfolk Standard Time' => 'Pacific/Norfolk',
        'North Asia East Standard Time' => 'Asia/Irkutsk',
        'North Asia Standard Time' => 'Asia/Krasnoyarsk',
        'North Korea Standard Time' => 'Asia/Pyongyang',
        'Omsk Standard Time' => 'Asia/Omsk',
        'Pacific SA Standard Time' => 'America/Santiago',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Pacific Standard Time (Mexico)' => 'America/Tijuana',
        'Pakistan Standard Time' => 'Asia/Karachi',
        'Paraguay Standard Time' => 'America/Asuncion',
        'Qyzylorda Standard Time' => 'Asia/Qyzylorda',
        'Romance Standard Time' => 'Europe/Paris',
        'Russia Time Zone 10' => 'Asia/Srednekolymsk',
        'Russia Time Zone 11' => 'Asia/Kamchatka',
        'Russia Time Zone 3' => 'Europe/Samara',
        'Russian Standard Time' => 'Europe/Moscow',
        'SA Eastern Standard Time' => 'America/Cayenne',
        'SA Pacific Standard Time' => 'America/Bogota',
        'SA Western Standard Time' => 'America/La_Paz',
        'Saint Pierre Standard Time' => 'America/Miquelon',
        'Sakhalin Standard Time' => 'Asia/Sakhalin',
        'Samoa Standard Time' => 'Pacific/Apia',
        'Sao Tome Standard Time' => 'Africa/Sao_Tome',
        'Saratov Standard Time' => 'Europe/Saratov',
        'SE Asia Standard Time' => 'Asia/Bangkok',
        'Singapore Standard Time' => 'Asia/Singapore',
        'South Africa Standard Time' => 'Africa/Johannesburg',
        'South Sudan Standard Time' => 'Africa/Juba',
        'Sri Lanka Standard Time' => 'Asia/Colombo',
        'Sudan Standard Time' => 'Africa/Khartoum',
        'Syria Standard Time' => 'Asia/Damascus',
        'Taipei Standard Time' => 'Asia/Taipei',
        'Tasmania Standard Time' => 'Australia/Hobart',
        'Tocantins Standard Time' => 'America/Araguaina',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'Tomsk Standard Time' => 'Asia/Tomsk',
        'Tonga Standard Time' => 'Pacific/Tongatapu',
        'Transbaikal Standard Time' => 'Asia/Chita',
        'Turkey Standard Time' => 'Europe/Istanbul',
        'Turks And Caicos Standard Time' => 'America/Grand_Turk',
        'Ulaanbaatar Standard Time' => 'Asia/Ulaanbaatar',
        'US Eastern Standard Time' => 'America/Indiana/Indianapolis',
        'US Mountain Standard Time' => 'America/Phoenix',
        'UTC' => 'UTC',
        'UTC+12' => 'Etc/GMT-12',
        'UTC+13' => 'Etc/GMT-13',
        'UTC-02' => 'Etc/GMT+2',
        'UTC-08' => 'Etc/GMT+8',
        'UTC-09' => 'Etc/GMT+9',
        'UTC-11' => 'Etc/GMT+11',
        'Venezuela Standard Time' => 'America/Caracas',
        'Vladivostok Standard Time' => 'Asia/Vladivostok',
        'Volgograd Standard Time' => 'Europe/Volgograd',
        'W. Australia Standard Time' => 'Australia/Perth',
        'W. Central Africa Standard Time' => 'Africa/Lagos',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'W. Mongolia Standard Time' => 'Asia/Hovd',
        'West Asia Standard Time' => 'Asia/Tashkent',
        'West Bank Standard Time' => 'Asia/Hebron',
        'West Pacific Standard Time' => 'Pacific/Port_Moresby',
        'Yakutsk Standard Time' => 'Asia/Yakutsk',
        'Yukon Standard Time' => 'America/Whitehorse',
    ];

    /**
     * The IANA zone a name stands for, or null where the catalogue has not
     * got it.
     *
     * **A miss is an answer, not a failure.** `Europe/Berlin` is no alias and
     * needs none; a name nobody here knows is {@see Resolver}'s next
     * question, not its last.
     */
    public static function iana(string $name): ?string
    {
        return self::WINDOWS[$name] ?? null;
    }

    /**
     * The whole catalogue, for a test to check line by line against the
     * system the names come from.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::WINDOWS;
    }
}
