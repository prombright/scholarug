<?php
declare(strict_types=1);

/**
 * Dial codes for the country-code dropdown on any mobile money payment
 * form. Scoped to countries where MTN and/or Airtel Money actually
 * operate -- a full 195-country list would just make the dropdown harder
 * to use for the countries that matter here. Uganda is first/default.
 *
 * Moved here from bulksms/lib/ so every app's payment forms share one list.
 */
final class CountryCodes
{
    public const LIST = [
        ['iso' => 'UG', 'dial' => '+256', 'name' => 'Uganda'],
        ['iso' => 'KE', 'dial' => '+254', 'name' => 'Kenya'],
        ['iso' => 'TZ', 'dial' => '+255', 'name' => 'Tanzania'],
        ['iso' => 'RW', 'dial' => '+250', 'name' => 'Rwanda'],
        ['iso' => 'SS', 'dial' => '+211', 'name' => 'South Sudan'],
        ['iso' => 'CD', 'dial' => '+243', 'name' => 'DR Congo'],
        ['iso' => 'ZM', 'dial' => '+260', 'name' => 'Zambia'],
        ['iso' => 'NG', 'dial' => '+234', 'name' => 'Nigeria'],
        ['iso' => 'GH', 'dial' => '+233', 'name' => 'Ghana'],
        ['iso' => 'CM', 'dial' => '+237', 'name' => 'Cameroon'],
        ['iso' => 'CI', 'dial' => '+225', 'name' => 'Ivory Coast'],
        ['iso' => 'MW', 'dial' => '+265', 'name' => 'Malawi'],
        ['iso' => 'MG', 'dial' => '+261', 'name' => 'Madagascar'],
        ['iso' => 'NE', 'dial' => '+227', 'name' => 'Niger'],
        ['iso' => 'TD', 'dial' => '+235', 'name' => 'Chad'],
        ['iso' => 'GA', 'dial' => '+241', 'name' => 'Gabon'],
        ['iso' => 'CG', 'dial' => '+242', 'name' => 'Congo-Brazzaville'],
    ];
}
