Open Badge Factory plugin
=================

Open Badge Factory is a cloud platform that provides the tools your organization needs to implement a meaningful and sustainable Open Badges system.

With the local_obf plugin you can issue Open Badges created in Open Badge Factory. To use the plugin, you need an account on
[https://openbadgefactory.com](https://openbadgefactory.com) (You can register for free, see
[https://openbadgefactory.com/en/pricing/](https://openbadgefactory.com/en/pricing/) for details about different service levels).

**IMPORTANT WARNING**

A Pro-level subscription is required for plugin versions 2.0.0 and newer.
Organisations on Basic or Premium plans should not upgrade to this version.

This plugin no longer uses the OBF APIv1. OAuth2-based API keys are now required.
Obsolete legacy API keys (APIv1 client-side certificates) must be replaced with new keys.
To continue using the plugin, ensure your OBF account is on the Pro level and that you have access to OAuth2 keys.

How to install
--------------

Moodle 4.1 and up:

1. Install the zip via Moodle's plugin page. Select "local" as the type of the plugin. (alternative: unzip to moodle's local subdirectory)
2. Update the database using the notifications page
3. Complete the [Post install steps](README.md#post-install)

Totara 11.0 and greater

Totara Learn does not include an add-on installer, all additional plugins must be installed manually by server administrators.

1. Download plugin from https://moodle.org/plugins/local_obf
2. Unzip the file into the Totara installation directory.
3. By using a site administrator account, go to Site administration → Notifications and upgrade Totara database
4. Complete the [Post install steps](README.md#post-install)

Post install
------------------

To connect to Open Badge Factory, the plugin needs an API key.

To generate the required OAuth2 API key, log in to Open Badge Factory. When logged in, navigate to `Admin tools > API`.

On the API key page click on `Generate new client secret` for OAuth2 Client Credentials. Give a description for the key and copy the client id and secret values into OBF Moodle plugin settings, in `Site administration > Open Badges > Settings`.

This plugin supports multiple clients on one Moodle installation.

Changelog
------------------

2.0.2

- Badge expiration date bug fixes.

2.0.1

- Awarding histories show the most recent badge issuing events first.
- Translation fixes.
- Minor bug fixes and improvements.

2.0.0

- Pro level subscription required.

- New features:
  - Switch to OBF API v2 on the backend. See documentation here: https://openbadgefactory.com/obf-api-v2/
  - Support sub-organisations for badge issuing.

- CSV export bug fixes improvements:
  - CSV exports can now handle large issuing histories (1,000+ badge issuing events).
  - CSV exports now include Issuer and Issued from fields.

- PHPUnit test fixes and PHPUnit test support for Moodle 5.1.

- Minor bug fixes and improvements.


1.1.4

- Privacy API fixes
- Minor bug fixes and improvements

1.1.3

- CSV export fixes
- Debug notice error fixes

1.1.2

- Removed the possibility to create new API connections with a legacy key.

1.1.1

- Minor settings page fixes.

1.1.0

- [Added] Introduced a queuing system for pending badges:
  Implemented functionality to queue badges that cannot be immediately issued due to factors such as server unavailability or client subscription issues.

1.0.11

- Improved badge sorting

1.0.10

- Minor improvements to badge listing: sorting, show full name

1.0.8

- Badge awarding rules and history fixes

1.0.7

- Passport email verification code fix

1.0.6

- Small fixes to plugin config and activity awarding rules

1.0.5

- Course completion awarding rule fix

1.0.4

- User profile page badge list fixes
- Minor bug fixes

1.0.3

- Fix issues related to categories when multiple clients are connected

1.0.2

- Fix deprecated code issues in Moodle 4.3

1.0.1

- Bug fix for DB upgrade
- Minor bug fixes

1.0.0

- New features
  - Pro customers (OAuth2) - Set specific badge permissions on Moodle categories (link OBF categories with Moodle categories)
  - Display OBF earned badges on student profile - this feature was moved from displayer block plugin to local-obf (the displayer block plugin has reached End Of Life)

- Major improvements
  - Awarding rule activity achievement: show only resources and activities with achievement, section names introduced, name and icon of resource/activity visible
  - New web notification preferences for:
    - All users: New badge is issued.
    - Teachers: New badge is issued to a student, A student’s badge has been revoked.
  - Awarding history : new export button

- Minor Improvements:
  - Public badges selected by default when connecting OB Passport account
  - Change some inconsistent behaviors and look and feel
  - Multiple translation modifications in AMOS (French mainly)

- Privacy API implemented

- Bugs fixes:
  - Export Moodle badges to OBF account restored

0.8.0

- Badge issuing report fixes

0.7.0

- MySQL reserved word column name fix

0.6.1

- User name field fix
- String escape fixes

0.6.0

- Include recipient name in issued badges
- Badge string escape fixes

0.5.5

- API auth fixes

0.5.4

- PostgreSQL query fix
- Replaced array\_key\_first function

0.5.3

- PostgreSQL query fix

0.5.2

- Fixed warnings for missing page context
- Fixed api call for all user badges when using legacy connection

0.5.1

- Connect multiple Factory clients with OAuth2
- Awarding rules bug fixes
- Other minor fixes and improvements

0.4

- Fixed problem with Moodle 3.10.1
- Added support for Totara program and certications
