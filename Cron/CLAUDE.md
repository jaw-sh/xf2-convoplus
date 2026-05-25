# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working in `src/addons/HappyBoard/ConvoPlus/Cron`.

## Scope

This directory belongs to the `HappyBoard/ConvoPlus` add-on (Convo+ by HappyBoard). Relative path inside the add-on: `Cron`.

## Directory Role

This area contains scheduled tasks registered by the add-on.

PHP classes here should map to the `HappyBoard\ConvoPlus\Cron` namespace.

## Working Notes

- Check the add-on root `CLAUDE.md` and `addon.json` before making behavioral changes.
- Keep changes consistent with the add-on's exported `_data/` metadata when routes, listeners, permissions, options, templates, or class extensions are involved.
- If this directory extends XenForo or another add-on, preserve the mirrored path and XFCP inheritance conventions.
- Avoid treating generated output, release archives, vendored dependencies, or npm packages as primary source unless the task specifically targets them.
