# Plugin Store promos

Marketing images for the Switchboard listing on the Craft Plugin Store, in the same theme as
[justinholt.com/plugins/craft-switchboard](https://justinholt.com/plugins/craft-switchboard).

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output: `out/switchboard-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, downsampled).
**JPEG, never PNG.** `fonts.css` is generated and gitignored.

The cover and the closing slide carry the Craft 6 beta caution, and every footer says
"Craft CMS 6 (beta)" — keep it there until Craft 6 and Switchboard leave beta.

| # | Slide |
| --- | --- |
| 1 | Cover — free, Craft 6 beta caution |
| 2 | Triggers |
| 3 | Channels (Laravel notifications) |
| 4 | Permission-aware deliveries |
| 5 | Per-recipient languages, Twig and Blade |
| 6 | Signed, SSRF-safe webhooks |
| 7 | Closing — summary, beta caution |
