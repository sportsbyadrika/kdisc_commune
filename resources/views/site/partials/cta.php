<?php /** Closing call-to-action band. @var App\Core\Template $this */ ?>
<section class="container-page pb-20">
    <div class="relative isolate overflow-hidden rounded-[2rem] bg-brand-900 px-6 py-14 text-center sm:px-16">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(40rem_20rem_at_100%_0%,var(--color-accent-500)_0%,transparent_60%),radial-gradient(40rem_24rem_at_0%_100%,var(--color-brand-600)_0%,transparent_60%)] opacity-70"></div>
        <h2 class="mx-auto max-w-2xl font-display text-3xl font-extrabold !text-white sm:text-4xl">Ready to work near home?</h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-white/75">Visit the centre for a walkthrough, or check seat availability for your dates now.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-primary btn-lg">Book a Seat <?= icon('arrow-right', 'size-4') ?></a>
            <a href="<?= e(url('contact')) ?>" class="btn btn-lg bg-white/10 text-white ring-1 ring-white/25 hover:bg-white/20">Talk to us</a>
        </div>
    </div>
</section>
