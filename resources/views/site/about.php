<?php
/**
 * @var App\Core\Template $this
 * @var int $totalSeats
 * @var array<string, mixed>|null $building
 */
$this->layout('layouts/site');
?>
<?= $this->component('page-hero', [
    'eyebrow' => 'About',
    'title' => 'Workspaces close to where Kerala lives',
    'subtitle' => 'Commune is a K-DISC initiative that brings professional, affordable workspaces to towns across Kerala.',
    'image' => $building['photo_path'] ?? null,
    'breadcrumb' => [['Home', url('home')], ['About']],
]) ?>
<section class="section">
    <div class="container-page grid gap-12 lg:grid-cols-[1.3fr_1fr]">
        <div class="prose-page max-w-none">
            <h2 class="!mt-0">Work Near Home</h2>
            <p>Long commutes to city offices cost time, money and energy. The <strong>Work Near Home</strong> programme of the Kerala Development and Innovation Strategic Council (K-DISC) creates shared workspaces in smaller towns so that remote workers, freelancers, students, startups and institutions can work productively without leaving their community.</p>
            <p>The Kottarakara centre is the first Commune. It offers open hot desks, enclosed dedicated seats, private executive cabins and a conference room — with high-speed internet, air conditioning, power backup and a pantry included.</p>
            <h2>Who is it for?</h2>
            <ul>
                <li>Remote workers and freelancers who need a quiet, reliable place to work</li>
                <li>Students preparing for exams or working on projects</li>
                <li>Startups, companies, NGOs and government bodies needing seats for their teams</li>
            </ul>
            <h2>Transparent and paperless</h2>
            <p>Register online, complete your KYC, choose your exact seat on the floor map and receive GST invoices and receipts by email — or let our front desk do it all for you.</p>
        </div>
        <aside class="space-y-4">
            <?= $this->component('stat', ['label' => 'Seats in Kottarakara', 'value' => $totalSeats, 'icon' => 'armchair', 'tone' => 'brand']) ?>
            <?= $this->component('stat', ['label' => 'Space types', 'value' => 4, 'icon' => 'layout-grid', 'tone' => 'accent', 'hint' => 'Flexi · Dedicated · Cabin · Conference']) ?>
            <?= $this->component('stat', ['label' => 'Open', 'value' => '6 days', 'icon' => 'clock', 'tone' => 'success', 'hint' => (string) config('app.org.hours')]) ?>
        </aside>
    </div>
</section>
<?= $this->partial('site/partials/cta') ?>
