<?php

/*
 * Customer-facing strings for in-app support.
 *
 * The conversation itself is written by the BPO platform and arrives already
 * translated; these are Nexmile's own words around it — the bubble's label and
 * what is said when support cannot be reached at all.
 */
return [

    // Shown on the bubble. From the server so the wording can change
    // for a festival or a bad day without an app release.
    'label' => 'Help',

    // The BPO platform could not be reached. The app shows the phone
    // number here: support is the one thing that must not be the
    // broken part.
    'unavailable' => 'Support is unavailable right now. Please call us and we will help.',

];
