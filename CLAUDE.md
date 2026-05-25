# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working in the `HappyBoard/ConvoPlus` add-on.

## What This Is

`HappyBoard/ConvoPlus` is a XenForo add-on titled "Convo+ by HappyBoard", version 2.4.0.

## Directory Map

- `Cli/` - XenForo CLI command classes.
- `Cron/` - scheduled tasks registered by the add-on.
- `Notifier/` - notification dispatchers.
- `XF/` - class extensions for XenForo core classes.
- `_data/` - exported XenForo metadata XML; this is the portable source of truth for add-on configuration.
- `_output/` - development output generated from or imported into XenForo development mode.
- `_releases/` - release archives and build artifacts.

## XenForo Metadata

- `addon.json` defines the add-on title, version, requirements, icon, and support metadata.
- `Setup.php`, when present, owns install, upgrade, and uninstall schema/data changes.
- `_data/` XML files are the portable metadata used for add-on export/build operations.
- `_output/` files are development-mode output and may be generated from the database.

Detected metadata files:

- `activity_summary_definitions.xml`
- `admin_navigation.xml`
- `admin_permission.xml`
- `advertising_positions.xml`
- `api_scopes.xml`
- `bb_code_media_sites.xml`
- `bb_codes.xml`
- `class_extensions.xml`
- `code_event_listeners.xml`
- `code_events.xml`
- `content_type_fields.xml`
- `cron.xml`
- `help_pages.xml`
- `member_stats.xml`
- `navigation.xml`
- `option_groups.xml`
- `options.xml`
- `permission_interface_groups.xml`
- `...and 9 more`

## Integration Points

Routes:

- None detected in this checkout.

Class extensions:

- XF\Alert\ConversationMessageHandler -> HappyBoard\ConvoPlus\XF\Alert\ConversationMessageHandler
- XF\Entity\ConversationMaster -> HappyBoard\ConvoPlus\XF\Entity\ConversationMaster
- XF\Entity\ConversationMessage -> HappyBoard\ConvoPlus\XF\Entity\ConversationMessage
- XF\Entity\ConversationRecipient -> HappyBoard\ConvoPlus\XF\Entity\ConversationRecipient
- XF\Entity\ConversationUser -> HappyBoard\ConvoPlus\XF\Entity\ConversationUser
- XF\Finder\ConversationRecipientFinder -> HappyBoard\ConvoPlus\XF\Finder\ConversationRecipientFinder
- XF\Pub\Controller\ConversationController -> HappyBoard\ConvoPlus\XF\Pub\Controller\ConversationController
- XF\Pub\Controller\ReportController -> HappyBoard\ConvoPlus\XF\Pub\Controller\ReportController
- XF\Repository\Conversation -> HappyBoard\ConvoPlus\XF\Repository\Conversation
- XF\Service\Conversation\MessageManagerService -> HappyBoard\ConvoPlus\XF\Service\Conversation\MessageManagerService
- XF\Service\Conversation\NotifierService -> HappyBoard\ConvoPlus\XF\Service\Conversation\NotifierService
- XF\Service\Conversation\ReplierService -> HappyBoard\ConvoPlus\XF\Service\Conversation\ReplierService

Code event listeners:

- None detected in this checkout.

## Requirements

- No explicit requirements in addon.json.

## Development Commands

```bash
# Run from the XenForo installation root.
php cmd.php xf:addon-export HappyBoard/ConvoPlus
php cmd.php xf:dev-rebuild HappyBoard/ConvoPlus
php cmd.php xf:addon-build HappyBoard/ConvoPlus
```

## Working Notes

- Keep PHP namespaces aligned with the add-on ID and directory path.
- When changing routes, permissions, options, phrases, listeners, or class extensions, update/export the corresponding `_data/*.xml` file.
- Entity structure changes usually require a matching `Setup.php` migration and a version bump in `addon.json`.
- Do not edit release artifacts in `_releases/` as source.
