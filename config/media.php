<?php

return [

    /*
     * Storefront and dish photos share the KYC bucket, which blocks all public
     * access, so they are served through signed URLs like every other object
     * we store.
     *
     * They are not sensitive — a customer is meant to see them — but a second
     * public bucket plus CloudFront is AWS console work that would block menu
     * management on infrastructure. The API returns a URL string either way,
     * so moving to a public bucket later changes nothing for the apps.
     */
    'disk' => env('MEDIA_DISK', env('KYC_DISK', 's3')),

    /*
     * Far longer than a KYC link, which grants access to an Aadhaar. A dish
     * photo leaks nothing, and a short TTL would mean a customer scrolling a
     * menu for ten minutes watches the images expire underneath them.
     *
     * It also has to outlive the client's own image cache, or every scroll
     * re-downloads.
     */
    'url_ttl_minutes' => 60 * 24,

    /*
     * What a merchant may upload. Generous on purpose: a phone photograph is
     * two to four megabytes and refusing it would mean refusing most
     * photographs taken on most phones.
     *
     * It is no longer what customers download — see `variants` below, which
     * is what stopped a four-megabyte original being sent to draw a
     * four-hundred-pixel card.
     */
    'max_size_kb' => 4096,

    /*
     * The size each kind of image is actually displayed at, keyed on the
     * first segment of its storage path.
     *
     * Widths are roughly twice the on-screen size, which is what a modern
     * phone's pixel density needs and where the returns stop. Quality is
     * WebP's scale: below about 70 the edges of lettering on a menu start to
     * smear, and above about 85 the file grows without anybody seeing a
     * difference.
     *
     * Everything is scaled **down** only. A small logo is left alone rather
     * than stretched.
     */
    'variants' => [
        // Dish photographs, shown as a thumbnail beside a menu row.
        'menu' => ['width' => 800, 'quality' => 78],

        // The storefront carousel, which is the biggest thing on the page.
        'storefront' => ['width' => 1400, 'quality' => 80],

        // Named explicitly by the uploader, because a logo shares the
        // storefront directory and does not need storefront dimensions.
        'logo' => ['width' => 400, 'quality' => 82],

        'banners' => ['width' => 1400, 'quality' => 80],
        'collections' => ['width' => 1000, 'quality' => 80],
        'categories' => ['width' => 600, 'quality' => 80],

        // Small round tiles on the home screen.
        'cuisines' => ['width' => 300, 'quality' => 82],

        /*
         * Proof that a delivery was left somewhere. Smaller and cheaper than
         * the rest: it is looked at when something is disputed, which is
         * rarely, and it is a doorway rather than a product photograph.
         */
        'door-photos' => ['width' => 1000, 'quality' => 72],

        /*
         * Somebody's own profile picture.
         *
         * The smallest thing we store, because it is drawn as a circle about
         * forty pixels across in a menu and about a hundred and twenty at the
         * top of the account screen. 256 covers the larger of those at twice
         * the density with nothing to spare, and a selfie off a phone arrives
         * at three thousand.
         */
        'avatars' => ['width' => 256, 'quality' => 82],
    ],

    'default_variant' => ['width' => 1200, 'quality' => 80],

    'mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
     * Banners may animate; dish photos and cuisine icons may not.
     *
     * A moving carousel slide is a design choice. A dish photo that animates
     * is a menu that will not sit still to be read, and sixty animating
     * cuisine icons on one screen is a phone getting warm.
     *
     * svg is deliberately absent from both. Laravel's `image` rule allows it,
     * and an SVG is a document that can carry script — not something to accept
     * from an upload and serve back under our own domain.
     */
    'banner_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

    /*
     * Slides in a restaurant's carousel.
     *
     * A carousel nobody swipes to the end of costs data for nothing, and every
     * slide is a signed URL the app fetches when the storefront opens.
     */
    'max_storefront_photos' => 8,

    /*
     * A door photo is not a dish photo.
     *
     * The 24-hour link above is right for a menu: the image leaks nothing and
     * a short TTL would expire under a customer's thumb mid-scroll. A picture
     * of somebody's front door, paired with the coordinates sitting next to it
     * in the same response, is the answer to "where does this person live" —
     * so its link lasts about as long as a delivery and no longer.
     *
     * Long enough to outlive a slow delivery and an app backgrounded at the
     * gate; short enough that a URL copied out of a log is dead by morning.
     */
    'door_url_ttl_minutes' => env('DOOR_PHOTO_URL_TTL_MINUTES', 180),

];
