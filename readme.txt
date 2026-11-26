=== ReviewWarden: Stop Fake and Spam Product Reviews for WooCommerce ===
Contributors: iqbal511138
Tags: woocommerce, reviews, spam, product reviews, fake reviews
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stops review bots without puzzles, sends link and keyword spam to the spam folder, holds reviews from non-buyers and warns you about review attacks.

== Description ==

Product reviews sell. Spam reviews with casino links, bots posting the same text on every product, or a wave of one star reviews from people who never bought anything do the opposite. Moderating them by hand takes time, and a puzzle on the review form scares real customers away.

ReviewWarden keeps your reviews real, quietly.

= What it does =

* **Invisible check.** A real visitor opening the product page gets a signed pass in the background. Bots that post straight to the form do not, and their reviews go to spam. No puzzles, no tracking, nothing for customers to do.
* **Safe start.** The check is only enforced after the first real review passes it, so a cache or theme problem can never block your customers.
* **Link and word spam.** Reviews with links or with words you choose go to spam.
* **Daily limit.** One visitor can leave a few reviews a day, not hundreds.
* **Real customers first.** Optionally hold reviews from people who never bought the product until you approve them. Buyers are published as usual.
* **Review attack alert.** An email when one product suddenly gets many one or two star reviews.
* **Dashboard** with spam stopped, new reviews, reviews waiting for you and your average rating.

ReviewWarden never hides or deletes honest reviews because of their rating. Low ratings are published or held exactly like high ones.

= Privacy =

ReviewWarden stores product IDs, ratings and the reason a review was stopped. IP addresses are only kept for one day as a scrambled fingerprint for the daily limit. No external requests.

== Installation ==

1. Install and activate ReviewWarden. WooCommerce must be active.
2. That is all. Open ReviewWarden, Settings, to change the checks.

== Frequently Asked Questions ==

= Do customers see anything? =

No. The check runs in the background.

= Where do stopped reviews go? =

To Comments, Spam, so you can look at them and restore any you want.

= Does it work with page caching? =

Yes. The pass is fetched fresh after the page loads, so cached pages work.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
