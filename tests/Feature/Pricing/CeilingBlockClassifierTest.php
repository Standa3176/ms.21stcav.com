<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\CeilingBlockClassifier;

/*
|--------------------------------------------------------------------------
| 260825-t4m — triage inside the margin-ceiling block
|--------------------------------------------------------------------------
|
| The single 50% ceiling mixes three unrelated things. Real rows, prod
| 2026-08-25, 48 published blocks:
|
|   CP4         cost £24.96   competitor £1,748.39   5,737%  cash £192  DATA FAULT
|   DBKT10027   cost £37.74   competitor £298.99       560%  cash £207  DATA FAULT
|   FW-98BZ30L  cost £3,209   competitor £6,756.91      76%  cash £1,339  review
|   47590       cost £1.16    competitor £2.22          59%  cash ~£0.50  noise
|
| Note CP4 and DBKT10027 carry MORE cash than several genuine opportunities.
| Any cash-first rule promotes broken records to the top of the queue, which is
| why margin outranks cash in classify().
|
| Classification changes nothing about whether a price is withheld — every
| block still blocks. It decides what an operator is shown first.
*/

function classifier(int $dataFaultBps = 20000, int $minCashPence = 500): CeilingBlockClassifier
{
    return new CeilingBlockClassifier($dataFaultBps, $minCashPence);
}

it('calls an extreme margin a data fault however much cash it carries', function (): void {
    // CP4: 5,737% on a £24.96 cost. £192/unit of apparent upside, and every
    // penny of it is fictional — the cost belongs to a different part.
    expect(classifier()->classify(573730, 19200))->toBe(CeilingBlockClassifier::DATA_FAULT);

    // DBKT10027 at 560% with £207 — same shape, same verdict.
    expect(classifier()->classify(56020, 20687))->toBe(CeilingBlockClassifier::DATA_FAULT);
});

it('keeps a plausible margin with real money as review', function (): void {
    // FW-98BZ30L: 75.5% on a £3,209 cost, £1,339/unit. A 98" commercial display
    // at £6,757 is an entirely believable competitor price.
    expect(classifier()->classify(7550, 133936))->toBe(CeilingBlockClassifier::REVIEW);
});

it('calls a high percentage on trivial money noise', function (): void {
    // 47590: a £1.16 cable at £2.21. 58.62% is a NORMAL retail markup — it only
    // looks alarming as a percentage.
    expect(classifier()->classify(5862, 50))->toBe(CeilingBlockClassifier::NOISE);
});

it('never calls a competitor at or below our price an opportunity', function (): void {
    // Three of the 48 published blocks were in this state. Releasing them would
    // CUT the price, so "blocked" must never be read as "money available".
    expect(classifier()->classify(6000, 0))->toBe(CeilingBlockClassifier::NO_UPSIDE)
        ->and(classifier()->classify(6000, -5000))->toBe(CeilingBlockClassifier::NO_UPSIDE);
});

it('ranks margin above cash, so a rich data fault cannot masquerade as review', function (): void {
    // The ordering that matters: same generous cash, opposite verdicts.
    expect(classifier()->classify(250000, 100000))->toBe(CeilingBlockClassifier::DATA_FAULT)
        ->and(classifier()->classify(7500, 100000))->toBe(CeilingBlockClassifier::REVIEW);
});

it('treats a data fault with no cash upside as still a data fault', function (): void {
    // Broken data is worth surfacing whether or not there is money attached.
    expect(classifier()->classify(400000, -100))->toBe(CeilingBlockClassifier::DATA_FAULT);
});

it('honours the configured thresholds rather than hardcoding them', function (): void {
    // 100% margin: a fault under a 50% fault-line, review under the 200% default.
    expect(classifier(dataFaultBps: 5000)->classify(10000, 50000))->toBe(CeilingBlockClassifier::DATA_FAULT)
        ->and(classifier()->classify(10000, 50000))->toBe(CeilingBlockClassifier::REVIEW);

    // £20 cash: noise under a £50 floor, review under the £5 default.
    expect(classifier(minCashPence: 5000)->classify(6000, 2000))->toBe(CeilingBlockClassifier::NOISE)
        ->and(classifier()->classify(6000, 2000))->toBe(CeilingBlockClassifier::REVIEW);
});

it('measures cash ex VAT, because VAT is not margin', function (): void {
    // £400.00 → £576.66 is £176.66 gross but £147.22 of margin. Reporting the
    // gross figure would overstate every opportunity by the VAT rate.
    expect(CeilingBlockClassifier::cashUpliftPence(57666, 40000, 2000))->toBe(14722);
});

it('exposes only data faults and review as actionable', function (): void {
    expect(CeilingBlockClassifier::isActionable(CeilingBlockClassifier::DATA_FAULT))->toBeTrue()
        ->and(CeilingBlockClassifier::isActionable(CeilingBlockClassifier::REVIEW))->toBeTrue()
        ->and(CeilingBlockClassifier::isActionable(CeilingBlockClassifier::NOISE))->toBeFalse()
        ->and(CeilingBlockClassifier::isActionable(CeilingBlockClassifier::NO_UPSIDE))->toBeFalse();
});

it('reads config when built from it', function (): void {
    config([
        'competitor.ceiling_data_fault_bps' => 30000,
        'competitor.ceiling_min_cash_pence' => 1000,
    ]);

    $c = CeilingBlockClassifier::fromConfig();

    expect($c->classify(25000, 50000))->toBe(CeilingBlockClassifier::REVIEW)
        ->and($c->classify(30000, 50000))->toBe(CeilingBlockClassifier::DATA_FAULT)
        ->and($c->classify(6000, 900))->toBe(CeilingBlockClassifier::NOISE);
});
