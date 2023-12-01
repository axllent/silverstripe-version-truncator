# Version truncator for Silverstripe

An extension for Silverstripe to automatically delete old versioned DataObject records from your database when a record is published, following predefined retention policies (see [configuration](#configuration)).

When a record is being edited (such as a Page), no changes are made until it is published, so it could have 50 draft versions while you work on the copy. When you publish the page, the module prunes the (by default) all draft copies, leaving just the 10 latest published versions (configurable).


## Features

* Delete all but the last XX **published** versions of a DataObject on publish
* Delete all but the last YY **draft** versions of a DataObject on publish
* Optionally keep old SiteTree objects where the URLSegment has changed (to preserve redirects)


## Tasks

The module adds three manual tasks to:

1. Force a run over the entire database - this task is generally not needed unless you either just install the module and wish to tidy up, or change your DataObject configurations.
2. Silverstripe does not currently delete any File records once the file had been physically deleted (probably due to the immediate post-delete functionality relating to internal file linking). I cannot see any purpose of keeping these records after this, so this task will remove all records pertaining to deleted files/folders.
3. Force a "reset", keeping only the latest published version of each currently published DataObject (regardless of policy). Unpublished / modified DataObjects are not touched.
4. Delete all archived DataObjects.

The tasks can be run via `/dev/tasks/TruncateVersionsTask`.


## Requirements

* Silverstripe ^4.5 || ^5.0


## Installation

`composer require axllent/silverstripe-version-truncator`


## Configuration

Configuration is optional (see [Default config](#default-config)), however you can create a YML file (eg: `app/_config/version-truncator.yml`):

```yaml
MyCustomObject:
  keep_versions: 5
  keep_drafts: 5
```

To skip pruning altogether for a particular DataObject, set `keep_versions: 0` for that object class.

To overwrite the global defaults, see [`_config/extension.yml`](_config/extension.yml), eg:

```yaml
SilverStripe\CMS\Model\SiteTree:
  keep_versions: 20
  keep_drafts: 10
```


## Default config

### SiteTree (and extending classes eg: Page etc)

On publish, the last 10 published versions are kept, and all draft copied are removed. The only exception is if the `URLSegment` and/or `ParentID` is has changed, in which case the module will keep a single record for each differing URLSegment to allow auto-redirection.


### All other DataObjects

For all other versioned DataObjects, only the latest published version is kept, and all drafts deleted. This can be adjusted per DataObject, or globally (see above).
