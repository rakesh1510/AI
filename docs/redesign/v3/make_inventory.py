#!/usr/bin/env python3
"""Builds the public-page inventory table used in the V3 report from tests/public_pages.json."""
import json, os
root = os.path.join(os.path.dirname(__file__), '..', '..', '..')
pages = json.load(open(os.path.join(root, 'tests', 'public_pages.json')))
notes = {
    'category-missing': 'error state (404)', 'tool-missing': 'error state (404)', 'search-empty': 'empty state', 'search-none': 'no-results state',
    'contact-sent': 'success state', 'inquiry-success': 'success state', 'error-404': 'error page', 'newsletter-confirm-invalid': 'invalid-link state',
    'unsubscribe-invalid': 'invalid-link state', 'recommend-results': 'wizard results', 'compare-result': 'comparison result',
}
print('| # | Page | Route | Note |\n|---|---|---|---|')
for i, (n, u) in enumerate(pages, 1):
    print(f'| {i} | {n} | `{u}` | {notes.get(n, "")} |')
