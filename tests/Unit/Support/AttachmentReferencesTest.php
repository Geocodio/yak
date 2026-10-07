<?php

use App\Support\AttachmentReferences;

it('wraps known labels in chips and leaves the rest alone', function () {
    $html = AttachmentReferences::linkInHtml('<p>See [Image #1] and [File #2] but not [Image #3]</p>', ['Image #1', 'File #2']);

    expect($html)->toBe('<p>See <span class="attachment-ref" data-attachment-ref="Image #1">[Image #1]</span> and '
        . '<span class="attachment-ref" data-attachment-ref="File #2">[File #2]</span> but not [Image #3]</p>');
});

it('does not touch labels inside code', function () {
    $html = '<pre><code>[Image #1]</code></pre><p><code>[Image #1]</code></p>';

    expect(AttachmentReferences::linkInHtml($html, ['Image #1']))->toBe($html);
});

it('returns the html unchanged when there are no references', function () {
    expect(AttachmentReferences::linkInHtml('<p>[Image #1]</p>', []))->toBe('<p>[Image #1]</p>');
});
