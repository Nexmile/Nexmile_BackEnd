<?php

return [

    /*
     * How long after delivery an order can still be rated.
     *
     * A window exists because a rating is evidence about one meal on one
     * evening. A review left five weeks later is memory, not evidence, and it
     * lands on a rider or a kitchen that has had two hundred orders since and
     * cannot act on it.
     *
     * Seven days is what the apps assume. It is deliberately generous: a
     * customer who ordered on Friday and opens the app the next weekend should
     * still be able to say what they thought.
     */
    'window_days' => env('REVIEWS_WINDOW_DAYS', 7),

    /*
     * A rider comment is shorter than a restaurant one on purpose.
     *
     * It is written about an identifiable worker, it reaches their record, and
     * nobody reads it but ops. Room for a sentence, not for an essay.
     */
    'rider_comment_max' => 300,

    /*
     * The tag vocabulary.
     *
     * Codes, not labels. The apps hold the translations, so renaming "tasty"
     * in Tamil is a client change rather than a migration, and a code that has
     * been stored survives its wording being rewritten.
     *
     * Split by *subject*, not by sentiment. Which tags an app offers for a
     * given star count is a question for the app — praise at four and five,
     * issues at one to three, as both apps already do. The server does not
     * enforce that pairing: a customer who gives four stars and still says the
     * food was too oily is telling the truth, and refusing the review would
     * lose the sentence as well as the tag.
     *
     * What the server does enforce is the subject. A delivery tag on the food
     * and a food tag on the rider are not opinions, they are mistakes, and
     * letting them through would put "food_spilled" on a kitchen's report.
     */
    'tags' => [

        /* Said about the food and the restaurant. */
        'food' => [
            // Praise
            'tasty',
            'fresh_hot',
            'good_portion',
            'good_packaging',
            'value_for_money',
            'hygienic',

            // Issues
            'cold_food',
            'poor_taste',
            'small_portion',
            'wrong_items',
            'missing_items',
            'poor_packaging',
            'too_oily',
        ],

        /* Said about the delivery and the rider. */
        'rider' => [
            // Praise
            'on_time',
            'polite',
            'followed_instructions',
            'careful_handling',
            'good_communication',

            // Issues
            'late',
            'rude',
            'didnt_follow_instructions',
            'food_spilled',
            'asked_extra_money',
            'unprofessional',
        ],
    ],

    /*
     * The most tags one review may carry, per subject.
     *
     * A review tagged with everything says nothing. This is a ceiling against
     * a loop, not a guideline for the app.
     */
    'max_tags' => 8,

    /*
     * Delivery tags that should reach a person rather than only a report.
     *
     * `asked_extra_money` is a rider collecting cash they were not owed and
     * `rude` is the one customers stop ordering over. Both are logged against
     * the rider today; neither pages anybody, because there is no channel that
     * reaches ops yet. When there is one, this is the list it watches — named
     * here so the decision about which tags are serious is not buried in a
     * notifier.
     */
    'rider_tags_needing_review' => [
        'asked_extra_money',
        'rude',
    ],

    /*
     * How long after delivery the "how was it?" reminder is sent.
     *
     * Long enough that the food has been eaten, short enough that the evening
     * is still the evening. The reminder is dropped at send time if the order
     * was rated in the meantime, so this is a delay, not a promise.
     */
    'reminder_after_minutes' => env('REVIEWS_REMINDER_AFTER_MINUTES', 45),

];
