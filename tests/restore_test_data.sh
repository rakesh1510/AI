#!/usr/bin/env bash
# Tests that exercise rating/comment forms append to data/*.json. Restore the tracked baseline before committing.
cd "$(dirname "$0")/.." && git checkout -- data/ratings.json data/tool_comments.json data/tool_votes.json data/clicks.json data/bookmarks.json 2>/dev/null; git status --short data | head
