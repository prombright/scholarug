<?php
declare(strict_types=1);

/**
 * The shared plan/pricing/trial-length catalog. A plain PHP array, not a
 * DB table -- the marketing site has no database connection today and a
 * rarely-changing price list doesn't need one. Each app's onboarding flow
 * and the marketing pricing.php page both read from this single source, so
 * a price change never has to be made in more than one place.
 */
final class Plans
{
    public const TRIAL_DAYS = 14;

    /**
     * plan_code => [app, label, billing_cycle, amount, currency, description]
     * billing_cycle matches subscriptions.billing_cycle: monthly|termly|yearly.
     */
    public const CATALOG = [
        'scholar_termly' => [
            'app' => 'scholar',
            'label' => 'Scholar — Termly',
            'billing_cycle' => 'termly',
            'amount' => 450000,
            'currency' => 'UGX',
            'description' => 'Full school management: admissions, fees, results, communication. Billed once per school term.',
        ],
        'scholar_yearly' => [
            'app' => 'scholar',
            'label' => 'Scholar — Yearly',
            'billing_cycle' => 'yearly',
            'amount' => 1200000,
            'currency' => 'UGX',
            'description' => 'Same as Termly, billed once a year at a lower effective rate.',
        ],
        'medicare_monthly' => [
            'app' => 'medicare',
            'label' => 'MediCare — Monthly',
            'billing_cycle' => 'monthly',
            'amount' => 250000,
            'currency' => 'UGX',
            'description' => 'Clinic/pharmacy/hospital records, billing and operations, billed monthly.',
        ],
        'medicare_yearly' => [
            'app' => 'medicare',
            'label' => 'MediCare — Yearly',
            'billing_cycle' => 'yearly',
            'amount' => 2500000,
            'currency' => 'UGX',
            'description' => 'Same as Monthly, billed once a year at a lower effective rate.',
        ],
        // Bulk SMS has no platform-subscription plan here -- it charges
        // purely per SMS/WhatsApp message via wallet debit, at volume-
        // discount tiers stored in the bulksms DB's sms_pricing_tiers
        // table (see bulksms/lib/Pricing.php), not this shared catalog.

        // Tagged 'scholar_ilearning', not 'scholar' -- these are the
        // live-class add-on, not part of Scholar's base plan, so they must
        // never show up in Plans::forApp('scholar')'s base-plan picker.
        'ilearning_live_termly' => [
            'app' => 'scholar_ilearning',
            'label' => 'iLearning Live Classes — Termly',
            'billing_cycle' => 'termly',
            'amount' => 150000,
            'currency' => 'UGX',
            'description' => 'Live online classes with a shared whiteboard, billed per term.',
        ],
        'ilearning_live_yearly' => [
            'app' => 'scholar_ilearning',
            'label' => 'iLearning Live Classes — Yearly',
            'billing_cycle' => 'yearly',
            'amount' => 400000,
            'currency' => 'UGX',
            'description' => 'Same as Termly, billed once a year at a lower effective rate.',
        ],
        // Tusome's billing_cycle values ('day'/'week'/'month') are new
        // strings local to this array, never a shared SQL ENUM -- safe
        // alongside every other app's differently-valued billing_cycle
        // column (tusome_passes.duration independently defines its own
        // ENUM, same as ilearning_addons already does in the same
        // database as subscriptions). 'tier' is a Tusome-only extra key
        // (basic/premium) read by tusome/purchase_initiate.php -- other
        // apps' plan entries simply don't have this key.
        'tusome_day_basic' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Day, Basic',
            'billing_cycle' => 'day',
            'tier' => 'basic',
            'amount' => 1000,
            'currency' => 'UGX',
            'description' => 'One day of basic-difficulty practice questions, all subjects.',
        ],
        'tusome_week_basic' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Week, Basic',
            'billing_cycle' => 'week',
            'tier' => 'basic',
            'amount' => 5000,
            'currency' => 'UGX',
            'description' => 'One week of basic-difficulty practice questions, all subjects.',
        ],
        'tusome_month_basic' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Month, Basic',
            'billing_cycle' => 'month',
            'tier' => 'basic',
            'amount' => 15000,
            'currency' => 'UGX',
            'description' => 'One month of basic-difficulty practice questions, all subjects.',
        ],
        'tusome_day_premium' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Day, Premium',
            'billing_cycle' => 'day',
            'tier' => 'premium',
            'amount' => 2000,
            'currency' => 'UGX',
            'description' => 'One day including advanced/challenge questions, all subjects.',
        ],
        'tusome_week_premium' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Week, Premium',
            'billing_cycle' => 'week',
            'tier' => 'premium',
            'amount' => 9000,
            'currency' => 'UGX',
            'description' => 'One week including advanced/challenge questions, all subjects.',
        ],
        'tusome_month_premium' => [
            'app' => 'tusome',
            'label' => 'Tusome — 1 Month, Premium',
            'billing_cycle' => 'month',
            'tier' => 'premium',
            'amount' => 28000,
            'currency' => 'UGX',
            'description' => 'One month including advanced/challenge questions, all subjects.',
        ],
    ];

    public static function get(string $planCode): ?array
    {
        return self::CATALOG[$planCode] ?? null;
    }

    /** @return array<string, array> plan_code => plan, for one app */
    public static function forApp(string $app): array
    {
        return array_filter(self::CATALOG, static fn (array $plan): bool => $plan['app'] === $app);
    }
}
