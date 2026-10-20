=== SEO Pilot – SEO Assistant ===
Contributors: seopilot
Tags: seo, content, audit, ai, arabic
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later

Local-first SEO audit with an approval-based AI content optimizer. Nothing is changed without your explicit confirmation.

== Description ==
* Audits posts and pages locally: structure, headings, readability, links, images, titles and descriptions, keyword placement, duplicate titles/descriptions and possible keyword cannibalization. English and Arabic.
* Optional AI recommendations through an OpenAI-compatible API you configure: SEO title, meta description, paragraph rewrites, outline, missing topics, FAQ ideas, refresh notes. The AI never invents facts as far as the plugin can detect: invented figures and links are flagged or removed, and anything needing verification is listed.
* Review every change as a diff, edit, accept, reject or regenerate. Apply with explicit confirmation; save as a draft copy instead of touching a live page; roll back from the history.
* Works alongside Yoast SEO, Rank Math and All in One SEO and never overwrites their fields without approval. Never changes canonicals, robots, structured data, slugs or dates.
* Does not show search volume, rankings or click-through rates because it has no such data.

== External services ==
When you press "Generate" in the Content SEO workspace, the selected page's title, URL path, current SEO title/description, your keywords, heading outline and paragraph text (up to the configured character limit) are sent to the AI provider you configure under SEO Pilot → Settings. Nothing is sent in the background or for other pages. You must confirm this in Settings before any request is made.

== Installation ==
See docs/INSTALL.md.

== Changelog ==
= 0.3.0 =
* Similar-content detection, internal link suggestions (applied only with approval), site-internal content gap, search-data import (Search Console CSV), per-criterion assessment, site report with CSV export.

= 0.2.0 =
* New Content SEO module (audit, workspace, AI recommendations, approval, revisions, rollback, history).
* Fixed: Arabic text was measured in bytes; descriptions saved while another SEO plugin was active were never output; approving a title overwrote the post title; rollback kept only one snapshot; API key stored in an autoloaded option.
* See docs/UPGRADE.md.
