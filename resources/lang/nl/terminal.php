<?php

return [
    // What the kassa shows while it follows a terminal payment
    'pending' => 'Wacht op de betaling op de terminal...',
    'verifying' => 'Wacht op de bevestiging van pay.nl...',
    'paid' => 'Betaald op de terminal. Bied de klant het terminalticket aan.',
    'paid_signature' => 'Betaald op de terminal. Laat de klant de kopie voor de handelaar tekenen en bied het terminalticket aan.',
    'cancelled_by_pos' => 'De betaling is geannuleerd vanaf de kassa. Probeer opnieuw om een nieuwe betaling te starten.',
    'timeout' => 'De betaling is verlopen: er werd niet betaald op de terminal. Probeer opnieuw om een nieuwe betaling te starten.',
    'expired' => 'De betaling is verlopen op de terminal: de klant heeft niet op tijd betaald. Probeer opnieuw.',
    'denied' => 'De kaart werd geweigerd. Probeer opnieuw of kies een andere betaalmethode.',
    'denied_reason' => 'De kaart werd geweigerd (:reason). Probeer opnieuw of kies een andere betaalmethode.',
    'stopped' => 'De betaling werd gestopt op de terminal. Probeer opnieuw.',
    'stopped_reason' => 'De betaling werd gestopt op de terminal: :reason. Probeer opnieuw.',
    'refunded' => 'De betaling is teruggestort aan de klant.',
    'no_answer' => 'De terminal gaf geen definitief antwoord. Probeer opnieuw om verder te wachten, er wordt geen nieuwe betaling gestart.',

    // Why a payment cannot be started
    'terminal_unknown' => 'Deze terminal is niet gekend bij pay.nl.',
    'terminal_offline' => 'De terminal is niet bereikbaar. Controleer de netwerkverbinding van de terminal en probeer opnieuw.',
    'terminal_inactive' => 'De terminal is niet actief (:status).',
    'terminal_busy' => 'De terminal is bezig met een andere betaling of weigert ze (:reason). Wacht tot de terminal vrij is en probeer opnieuw.',
    'starting' => 'Deze betaling wordt al gestart op de terminal.',

    // Why a payment cannot be booked
    'not_configured' => 'Pay.nl is niet ingesteld, de terminalbetaling kan niet afgewerkt worden.',
    'not_started' => 'Start de betaling eerst op de terminal.',
    'booking' => 'Deze terminalbetaling wordt al geboekt.',
    'not_paid' => 'De betaling op de terminal is niet geslaagd.',
    'amount_mismatch' => 'Het betaalde bedrag op de terminal komt niet overeen met het te betalen bedrag.',
    'unverifiable' => 'Kon de terminalbetaling niet controleren: :reason',

    // The printed card receipt
    'merchant_copy' => '--- Kopie handelaar ---',
    'signature' => 'Handtekening klant:',
];
