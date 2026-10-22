<?php

return [
    // What the kassa shows while it follows a terminal payment
    'pending' => 'Waiting for the payment on the terminal...',
    'verifying' => 'Waiting for pay.nl to confirm...',
    'paid' => 'Paid on the terminal. Offer the customer the terminal receipt.',
    'paid_signature' => 'Paid on the terminal. Have the customer sign the merchant copy and offer the terminal receipt.',
    'cancelled_by_pos' => 'The payment was cancelled from the till. Try again to start a new payment.',
    'timeout' => 'The payment expired: nothing was paid on the terminal. Try again to start a new payment.',
    'expired' => 'The payment expired on the terminal: the customer did not pay in time. Try again.',
    'denied' => 'The card was declined. Try again or choose another payment method.',
    'denied_reason' => 'The card was declined (:reason). Try again or choose another payment method.',
    'stopped' => 'The payment was stopped on the terminal. Try again.',
    'stopped_reason' => 'The payment was stopped on the terminal: :reason. Try again.',
    'refunded' => 'The payment was refunded to the customer.',
    'no_answer' => 'The terminal gave no final answer. Try again to keep waiting, no new payment is started.',

    // Why a payment cannot be started
    'terminal_unknown' => 'Pay.nl does not know this terminal.',
    'terminal_offline' => 'The terminal cannot be reached. Check its network connection and try again.',
    'terminal_inactive' => 'The terminal is not active (:status).',
    'terminal_busy' => 'The terminal is busy with another payment or refuses it (:reason). Wait until it is free and try again.',
    'starting' => 'This payment is already being started on the terminal.',

    // Why a payment cannot be booked
    'not_configured' => 'Pay.nl is not configured, the terminal payment cannot be completed.',
    'not_started' => 'Start the payment on the terminal first.',
    'booking' => 'This terminal payment is already being booked.',
    'not_paid' => 'The payment on the terminal did not succeed.',
    'amount_mismatch' => 'The amount paid on the terminal does not match the amount due.',
    'unverifiable' => 'Could not verify the terminal payment: :reason',

    // The printed card receipt
    'merchant_copy' => '--- Merchant copy ---',
    'signature' => 'Customer signature:',
];
