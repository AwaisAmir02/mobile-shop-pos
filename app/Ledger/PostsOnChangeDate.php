<?php

namespace App\Ledger;

/**
 * For a document with no date of its own that a change belongs to, like a
 * product's stock being corrected. Its difference posts on the day it's found,
 * to the account it asked for, not back on the line's date.
 */
interface PostsOnChangeDate
{
}
