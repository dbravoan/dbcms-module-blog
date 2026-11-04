# Blog privacy

Declare `legal.processing` owner `blog_comments` with purpose, legal basis, data,
retention, recipients/transfers and positive `retention_days` before collecting
comments. All supplied comment forms display the shared privacy notice; the
submission service enforces the same requirement. The period runs from submission.

Run `php artisan blog:privacy export --id=123 --verified` or `erase` after verifying
the request and scope. Exports go to private local storage; remove them after
fulfilment. `--verified` confirms the operator's review; it does not perform it.
The daily `blog:privacy prune` uses the declared period, including inactive module
data. Erasing a parent preserves other people's replies as root comments and
invalidates public HTML caches. Review separately any personal information quoted
by other authors. Notifications retain record IDs, without copying contact details.

Reassign authored posts before deleting their author account. The guard also
applies while Blog is inactive. Published article content and media remain editorial
records and use their owner publication/removal controls.
