# Changelog

Notable changes to this project will be documented in this file.

## [3.1.0]

- Add task option to delete all archived DataObjects


## [3.0.1]

- Support for Silverstripe 5
- Ensure versioned object hasStages()
- Move set_reading_mode() to onAfterPublish()


## [3.0.0]

- Major rewrite, breaking changes - support for all versioned DataObjects
- Deletion policy per class type (and extending classes)
- Prune only on `onPublish()` to simplify and reduce overheads
- Modify tasks


## [2.0.3]

- Switch to silverstripe-vendormodule


## [2.0.2]

- Replace default config with static variables


## [2.0.1]

- Fix potential dependency loop


## [2.0.0]

- Add support for SilverStripe 4 (new SilverStripe 3 branch)
- Rewrite of the internals
- Add task to manually run cleanup
- Update docs


## [1.0.0]

- Adopt semantic versioning releases
- Release versions
