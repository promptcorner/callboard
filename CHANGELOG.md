# Changelog

## [2.4.0](https://github.com/promptcorner/callboard/compare/v2.3.0...v2.4.0) (2026-09-13)


### Features

* let the director pick the track that every following phone opens ([#183](https://github.com/promptcorner/callboard/issues/183)) ([de14c2b](https://github.com/promptcorner/callboard/commit/de14c2b7363c75539d17cf71c1d8bd3a6e001cb7))
* store director notes as callboard_note comments ([#186](https://github.com/promptcorner/callboard/issues/186)) ([857dbf8](https://github.com/promptcorner/callboard/commit/857dbf8e57ff62bc4a6936d5e92dfbef4583626e))
* support multisite networks, with a service worker and manifest for each site ([#179](https://github.com/promptcorner/callboard/issues/179)) ([f48b7d7](https://github.com/promptcorner/callboard/commit/f48b7d71139b75167fae5af59a98687379195851))
* use the site icon for the app icon and splash screens ([#175](https://github.com/promptcorner/callboard/issues/175)) ([b96111a](https://github.com/promptcorner/callboard/commit/b96111a0fb03081d23bf2483e383c6636450620b))


### Bug Fixes

* move third-party extension routes under ext/, reserve namespaces, and add the REST nonce to app data ([#189](https://github.com/promptcorner/callboard/issues/189)) ([0350a84](https://github.com/promptcorner/callboard/commit/0350a84e5162ce2447a616e794b1d957f0e19367))
* notify cast about director notes ([#187](https://github.com/promptcorner/callboard/issues/187)) ([c651c1d](https://github.com/promptcorner/callboard/commit/c651c1de7539b1a618da4dca8d8ee8eeb45aa4ff))

## [2.3.0](https://github.com/promptcorner/callboard/compare/v2.2.0...v2.3.0) (2026-09-12)


### Features

* add an extension API, and rebuild the count-in, audio quality label and Home Screen badge on it ([#137](https://github.com/promptcorner/callboard/issues/137)) ([de885f8](https://github.com/promptcorner/callboard/commit/de885f8ce85947fa8560935538ee9912d02be80b))
* add Director and Cast member roles ([#177](https://github.com/promptcorner/callboard/issues/177)) ([975cfe9](https://github.com/promptcorner/callboard/commit/975cfe972b26c4aa002322cfd44a85693c1dfc58))
* filters on callboard_set_data below priority 5 no longer see a track's top-level bpm and quality ([#137](https://github.com/promptcorner/callboard/issues/137)) ([de885f8](https://github.com/promptcorner/callboard/commit/de885f8ce85947fa8560935538ee9912d02be80b))
* show how much each track was practiced since the last call ([#157](https://github.com/promptcorner/callboard/issues/157)) ([33e7031](https://github.com/promptcorner/callboard/commit/33e70312b85c836ddc9e1bd5fbf123b16abe1f57))
* the track in the callboard:track event is now a frozen copy instead of the player's own track object ([#137](https://github.com/promptcorner/callboard/issues/137)) ([de885f8](https://github.com/promptcorner/callboard/commit/de885f8ce85947fa8560935538ee9912d02be80b))


### Bug Fixes

* clean up extension commands and discard stale badge results ([#164](https://github.com/promptcorner/callboard/issues/164)) ([6e47029](https://github.com/promptcorner/callboard/commit/6e47029f20b146b68f70766c76dd09768cdd2bc0))
* close Now Playing with the back button, Escape or a close button instead of a swipe ([#154](https://github.com/promptcorner/callboard/issues/154)) ([8926af4](https://github.com/promptcorner/callboard/commit/8926af4840c401115b06ae686a5675830d1ad0a6))
* keep player scripts in footer ([#153](https://github.com/promptcorner/callboard/issues/153)) ([24ae648](https://github.com/promptcorner/callboard/commit/24ae648082394c4f3569eee769efa11d3cfd0c71))
* keep the level meter moving on the home page ([#122](https://github.com/promptcorner/callboard/issues/122)) ([0e54d9c](https://github.com/promptcorner/callboard/commit/0e54d9cfb328ec930f9fa6b6e420adb7367bf364))
* load the Playground demo again after the repository moved ([#132](https://github.com/promptcorner/callboard/issues/132)) ([2fd1be5](https://github.com/promptcorner/callboard/commit/2fd1be5f4566f913c2b2412c7126291a191f93a4))
* make the landing page demo preview accurate and easy to start ([#156](https://github.com/promptcorner/callboard/issues/156)) ([a06ad5e](https://github.com/promptcorner/callboard/commit/a06ad5efdb535514823574dd2347e305a582089d))
* only mark a playlist as saved offline once it can open without a connection ([#135](https://github.com/promptcorner/callboard/issues/135)) ([c18ef33](https://github.com/promptcorner/callboard/commit/c18ef33487bc9fa50c8365b5c3054f46838b1520))
* preserve extension assets in the offline cache after updates ([#152](https://github.com/promptcorner/callboard/issues/152)) ([106948c](https://github.com/promptcorner/callboard/commit/106948c976d09cf0d9921fdb12df23dab8534065))
* stabilize extension cache variants and memoize active registry resolution ([#150](https://github.com/promptcorner/callboard/issues/150)) ([d24449d](https://github.com/promptcorner/callboard/commit/d24449d886c9c2af56ec4e26e3548d675c839d70))
* start the gapless loop when both ends are set quickly ([#167](https://github.com/promptcorner/callboard/issues/167)) ([540c31b](https://github.com/promptcorner/callboard/commit/540c31b8b159230986d89df9478232e5dcc968ef))
* stop calling imagedestroy(), which is deprecated in PHP 8.5 ([#136](https://github.com/promptcorner/callboard/issues/136)) ([75c1a70](https://github.com/promptcorner/callboard/commit/75c1a70d5b2f6c2791658487ac89ebd109670ec5))

## [2.2.0](https://github.com/promptcorner/callboard/compare/v2.1.0...v2.2.0) (2026-09-12)


### Features

* declare the data model with register_post_meta ([#115](https://github.com/promptcorner/callboard/issues/115)) ([9fd95b6](https://github.com/promptcorner/callboard/commit/9fd95b66d0a1f680ab4ad93d57a6448331d2de8a))
* give the deck one system, and a size it earns ([#105](https://github.com/promptcorner/callboard/issues/105)) ([cac06fc](https://github.com/promptcorner/callboard/commit/cac06fc36eadd0acfd437523b987ca0fd85ffc93))
* Now Playing is a screen, not a taller bar ([#110](https://github.com/promptcorner/callboard/issues/110)) ([8df5a93](https://github.com/promptcorner/callboard/commit/8df5a93e3c907bc8e980e76ae3fcbc3ad0f3820b))
* PHP unit tests, one Share, and a cover for every set ([#107](https://github.com/promptcorner/callboard/issues/107)) ([baa3df9](https://github.com/promptcorner/callboard/commit/baa3df9f34ea9677472ac9edb68ebe65289f0cd2))
* quality tiers, and a manifest that keeps up with the sets ([#109](https://github.com/promptcorner/callboard/issues/109)) ([6b92f0f](https://github.com/promptcorner/callboard/commit/6b92f0f545a4c20ca0b63116735d47d11942564f))
* real recordings, per-track artist and quality, and a filament that ranges itself ([#94](https://github.com/promptcorner/callboard/issues/94)) ([ad75627](https://github.com/promptcorner/callboard/commit/ad75627a3a75cf395b7397163bcedeff92863538))
* the tab carries the track, and durable storage that actually asks ([#108](https://github.com/promptcorner/callboard/issues/108)) ([bb11417](https://github.com/promptcorner/callboard/commit/bb114176ec9e86131a3a840571d2dcdbd9b36fce))


### Bug Fixes

* a push endpoint must be somewhere the internet can reach ([#117](https://github.com/promptcorner/callboard/issues/117)) ([2324af7](https://github.com/promptcorner/callboard/commit/2324af79709fa53edc317b34dfae0dc16d83b38e))
* fetch the truest copy available, and stop inflating it ([#104](https://github.com/promptcorner/callboard/issues/104)) ([cfb6b82](https://github.com/promptcorner/callboard/commit/cfb6b82aba8d7e70590fb9aeae3f932b376257c5))
* fit a long set name inside its cover ([#93](https://github.com/promptcorner/callboard/issues/93)) ([c28ea57](https://github.com/promptcorner/callboard/commit/c28ea57c188c2d59e025064c4247fb2c831e8485))
* keep the offline badge inside the row ([#106](https://github.com/promptcorner/callboard/issues/106)) ([d2d8ffe](https://github.com/promptcorner/callboard/commit/d2d8ffe9c63b0fbfb2df4e30618956e156529f14))
* one column in Now Playing, and unit symbols that keep their casing ([#112](https://github.com/promptcorner/callboard/issues/112)) ([8380fbb](https://github.com/promptcorner/callboard/commit/8380fbb4cdffa8162d290fcb04319917c430232b))


### Dependencies

* **deps-dev:** bump globals from 16.5.0 to 17.12.0 ([#102](https://github.com/promptcorner/callboard/issues/102)) ([64d28a6](https://github.com/promptcorner/callboard/commit/64d28a68163261de7c2048c0db1f7ead3c5cb277))
* **deps:** bump crate-ci/typos from 1.50.0 to 1.50.1 in the actions-minor-and-patch group ([#103](https://github.com/promptcorner/callboard/issues/103)) ([1a93116](https://github.com/promptcorner/callboard/commit/1a93116c19b4c8258bbdd2cdbe02fdbec1cbe16e))

## [2.1.0](https://github.com/josephfusco/callboard/compare/v2.0.1...v2.1.0) (2026-09-12)


### Features

* export a set as a folder a car stereo can read ([#89](https://github.com/josephfusco/callboard/issues/89)) ([c8e6a1b](https://github.com/josephfusco/callboard/commit/c8e6a1b480601ae78763264c369be76aa1a5ccc5))
* export and import a set as a single .callboard file ([#87](https://github.com/josephfusco/callboard/issues/87)) ([402d2eb](https://github.com/josephfusco/callboard/commit/402d2eb206c8556355442bd0eb80b3fbdff444f7))
* fill a set's offline copies from a file ([#91](https://github.com/josephfusco/callboard/issues/91)) ([a72ad94](https://github.com/josephfusco/callboard/commit/a72ad94e62f6097a1a56010d8ae819e2e741447f))
* gate the front end with whatever auth the site already has ([#90](https://github.com/josephfusco/callboard/issues/90)) ([75e10a8](https://github.com/josephfusco/callboard/commit/75e10a85b95c9c4e0835106070d063317682d094))
* send the playing track to the phone next to you ([#92](https://github.com/josephfusco/callboard/issues/92)) ([20940b1](https://github.com/josephfusco/callboard/commit/20940b1c6fd23d1e589dd4c56875d6a63324b636))

## [2.0.1](https://github.com/josephfusco/callboard/compare/v2.0.0...v2.0.1) (2026-09-11)


### Bug Fixes

* drop a stray screenshot that shipped in the package ([#72](https://github.com/josephfusco/callboard/issues/72)) ([6b30513](https://github.com/josephfusco/callboard/commit/6b30513800080400e9e0ff7351296cfab186a9c7))

## [2.0.0](https://github.com/josephfusco/callboard/compare/v1.6.1...v2.0.0) (2026-09-11)


### ⚠ BREAKING CHANGES

* remove the dot-matrix display ([#71](https://github.com/josephfusco/callboard/issues/71))
* the transport is previous, play, next ([#62](https://github.com/josephfusco/callboard/issues/62))

### Features

* remove the dot-matrix display ([#71](https://github.com/josephfusco/callboard/issues/71)) ([c0fa513](https://github.com/josephfusco/callboard/commit/c0fa5133bb9c4217993907ecca2f1efebef9b2db))
* the deck reads like a dashboard, with a dot-matrix display option ([#67](https://github.com/josephfusco/callboard/issues/67)) ([ef6d468](https://github.com/josephfusco/callboard/commit/ef6d468d80c154dad6689f38ffe38f0f8bf3ef37))
* the dot-matrix display draws its own glyphs, a spectrum, and a swimmer ([#68](https://github.com/josephfusco/callboard/issues/68)) ([6b9b085](https://github.com/josephfusco/callboard/commit/6b9b085cbc00fc6ef8a14c03813518bdbbf5c17c))
* the transport is previous, play, next ([#62](https://github.com/josephfusco/callboard/issues/62)) ([f9fc59d](https://github.com/josephfusco/callboard/commit/f9fc59d0965bc5c1a45ebb5b60b7ceef0365c228))


### Bug Fixes

* re-importing a set no longer deletes its cover ([#65](https://github.com/josephfusco/callboard/issues/65)) ([230150d](https://github.com/josephfusco/callboard/commit/230150df019dd3256d50185c25626c9f7632a2f7))
* the deck as one system ([#69](https://github.com/josephfusco/callboard/issues/69)) ([c8acc53](https://github.com/josephfusco/callboard/commit/c8acc53c8500b5df17b6d8331f5001688b9fa471))
* the list and the deck share one right edge ([#70](https://github.com/josephfusco/callboard/issues/70)) ([e778dc3](https://github.com/josephfusco/callboard/commit/e778dc3bc2c4ae9a8b79f335f510df3fc6bd3fc2))
* the waveform is bars alone, centred, no reflection ([#66](https://github.com/josephfusco/callboard/issues/66)) ([6350082](https://github.com/josephfusco/callboard/commit/6350082375eac029f9411c222ce079efc1bd3695))

## [1.6.1](https://github.com/josephfusco/callboard/compare/v1.6.0...v1.6.1) (2026-09-09)


### Bug Fixes

* open Playground in seamless mode so its toolbar stays off the player ([#56](https://github.com/josephfusco/callboard/issues/56)) ([1ab8882](https://github.com/josephfusco/callboard/commit/1ab88827bc1abbb6cb51386d6a0630cf18aa6bca))
* stamp demo asset URLs with the commit so Pages caching never shows an old build ([#58](https://github.com/josephfusco/callboard/issues/58)) ([bdb1daa](https://github.com/josephfusco/callboard/commit/bdb1daad20000b440b2b7cb74feb3efe1a9867ac))

## [1.6.0](https://github.com/josephfusco/callboard/compare/v1.5.0...v1.6.0) (2026-09-09)


### Features

* a refinement pass on the deck and lists ([#32](https://github.com/josephfusco/callboard/issues/32)) ([9bac90e](https://github.com/josephfusco/callboard/commit/9bac90eb68ba6c04e91aba3ba5f38954015d0fe1))
* an accent colour, and a small developer API ([#47](https://github.com/josephfusco/callboard/issues/47)) ([1542d73](https://github.com/josephfusco/callboard/commit/1542d738445297b9364da20ddc61ae67552e7bda))
* the board ([#40](https://github.com/josephfusco/callboard/issues/40)) ([c72f044](https://github.com/josephfusco/callboard/commit/c72f04481c79cc2212edaa9df6532b10182ad43a))
* the filament behaves like one ([#26](https://github.com/josephfusco/callboard/issues/26)) ([c90b000](https://github.com/josephfusco/callboard/commit/c90b0005715793f649967f4dd0577ba9bbe07d3a))
* the landing page runs a live copy in WordPress Playground ([#49](https://github.com/josephfusco/callboard/issues/49)) ([c2f8dc6](https://github.com/josephfusco/callboard/commit/c2f8dc6d3720c60c96d53da792f2584d1f848ee0))
* the play button answers like a Magic 8-Ball ([#30](https://github.com/josephfusco/callboard/issues/30)) ([329ab25](https://github.com/josephfusco/callboard/commit/329ab25c9c6e0690c9fae9721221f2104e69c8ec))


### Bug Fixes

* an iOS Safari pass on the front end ([#37](https://github.com/josephfusco/callboard/issues/37)) ([0fc0fd7](https://github.com/josephfusco/callboard/commit/0fc0fd725bb4b04c7fd885d494a9dd4b3470e280))
* play and pause the way YouTube does it ([#39](https://github.com/josephfusco/callboard/issues/39)) ([ecce724](https://github.com/josephfusco/callboard/commit/ecce7246e6bba225b4214d0984401a2b998f9dcc))
* switching tracks no longer pauses the new one ([#29](https://github.com/josephfusco/callboard/issues/29)) ([ad4800b](https://github.com/josephfusco/callboard/commit/ad4800b617ec3ac57d111a53a3363259c555aa07))
* the 8-Ball has a real viewing window ([#31](https://github.com/josephfusco/callboard/issues/31)) ([69449de](https://github.com/josephfusco/callboard/commit/69449dee8ffcbbc8aaafdd2b3aff0cf89c963d09))
* the board is a list, not a stack of cards ([#51](https://github.com/josephfusco/callboard/issues/51)) ([9184dea](https://github.com/josephfusco/callboard/commit/9184dea47bb3fdaef7e82cf46207896ceeb28474))
* the filament is the deck's top edge again, and the wave sits flush beneath it ([#34](https://github.com/josephfusco/callboard/issues/34)) ([a65f810](https://github.com/josephfusco/callboard/commit/a65f810547b066b61c8bd88d3270a646a0f88063))
* the play key is a solid disc again ([#35](https://github.com/josephfusco/callboard/issues/35)) ([c4cf101](https://github.com/josephfusco/callboard/commit/c4cf1013598478bb31fe58ebf9fa72cbbef32485))
* the set header keeps to one line on small phones ([#50](https://github.com/josephfusco/callboard/issues/50)) ([27838dc](https://github.com/josephfusco/callboard/commit/27838dcf29197f7d7be9bccf361bc348c725aec0))


### Performance Improvements

* playback moves only compositor transforms ([#52](https://github.com/josephfusco/callboard/issues/52)) ([02e5dfa](https://github.com/josephfusco/callboard/commit/02e5dfa1b4160018119bd31a1dfe926d3823c971))

## [1.5.0](https://github.com/josephfusco/callboard/compare/v1.4.2...v1.5.0) (2026-09-07)


### Features

* a deeper deck, with the controls drawn as things you can press ([#21](https://github.com/josephfusco/callboard/issues/21)) ([604dc52](https://github.com/josephfusco/callboard/commit/604dc52a1fab2512d3555dec8fe90d4d30ec91bc))
* a gapless A/B loop while the page is in front ([#25](https://github.com/josephfusco/callboard/issues/25)) ([d552105](https://github.com/josephfusco/callboard/commit/d552105bc3efa0a517b442f9ca6053d4ed821a12))
* lyric cues as a text track, one player per site, push subscriptions that survive rotation ([#23](https://github.com/josephfusco/callboard/issues/23)) ([2e29e6c](https://github.com/josephfusco/callboard/commit/2e29e6c03973f8ff49f0149f33de31b1454b77bc))
* waveform in the deck, and a deck that keeps one height ([#18](https://github.com/josephfusco/callboard/issues/18)) ([1f85655](https://github.com/josephfusco/callboard/commit/1f8565538f367e91f2e663f634d72b278a98e19a))


### Bug Fixes

* the deck row never collides with the controls, and saving knows about space ([#17](https://github.com/josephfusco/callboard/issues/17)) ([ccb3e89](https://github.com/josephfusco/callboard/commit/ccb3e89582aa6b0bcbfa6ba2dd9d5b3584b918b9))

## [1.4.2](https://github.com/josephfusco/callboard/compare/v1.4.1...v1.4.2) (2026-09-07)


### Bug Fixes

* polish the set header, deck time row, and stylesheet ([#15](https://github.com/josephfusco/callboard/issues/15)) ([a750016](https://github.com/josephfusco/callboard/commit/a750016d2d66dc4a900e57120d7ae19ef9cd8215))

## [1.4.1](https://github.com/josephfusco/callboard/compare/v1.4.0...v1.4.1) (2026-09-07)


### Dependencies

* **deps-dev:** bump @wordpress/scripts to 34 and @wordpress/env to 11 ([#13](https://github.com/josephfusco/callboard/issues/13)) ([98ac56a](https://github.com/josephfusco/callboard/commit/98ac56a16d54b0703c40401ea78e34b7e04d4457))

## [1.4.0](https://github.com/josephfusco/callboard/compare/v1.3.2...v1.4.0) (2026-09-07)


### Features

* AirPlay and Cast from the deck ([#11](https://github.com/josephfusco/callboard/issues/11)) ([29618ec](https://github.com/josephfusco/callboard/commit/29618ec1649509c9d53268dd61dc9a774a87d503))
* share a set from its page ([#12](https://github.com/josephfusco/callboard/issues/12)) ([f86efba](https://github.com/josephfusco/callboard/commit/f86efba4be77587013ceb230d6debdf0e1cf50bf))
* speed chip slows playback and keeps the pitch ([#10](https://github.com/josephfusco/callboard/issues/10)) ([8cfc4ed](https://github.com/josephfusco/callboard/commit/8cfc4ed703dfc11754f291a8849210fa71c21717))


### Bug Fixes

* declare the license in the plugin header and mark tested up to 7.1 ([a50b072](https://github.com/josephfusco/callboard/commit/a50b0726ab724cdd431ba1e3d3e78bf165f97abb))
* haptics fire on iOS taps ([#9](https://github.com/josephfusco/callboard/issues/9)) ([8452c1c](https://github.com/josephfusco/callboard/commit/8452c1c82bc72dc13b25cc6fe632aaa16705325f))


### Dependencies

* **deps:** bump crate-ci/typos from 1.49.0 to 1.50.0 ([#1](https://github.com/josephfusco/callboard/issues/1)) ([6c42db5](https://github.com/josephfusco/callboard/commit/6c42db5426881121bc5ec0ed4623212725ed2244))

## 1.0.0
- First release: sets as posts, tracks as attachments, persistent player, installable app, offline saving, Web Push notices, WP-CLI fetching with yt-dlp, folder import.
