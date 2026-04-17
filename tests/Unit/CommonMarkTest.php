<?php

use League\CommonMark\CommonMarkConverter;

it('converts markdown to HTML', function () {
    $html = (new CommonMarkConverter)->convert('**bold**')->getContent();

    expect($html)->toContain('<strong>bold</strong>');
});
