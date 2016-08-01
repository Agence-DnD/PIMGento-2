### [Not Released]

  * fix: refactoring on attribute import
  * add: new event `pimgento_attribute_get_specific_columns_add_after` that allows to add other columns for attribute definition
  * add error message when import file with an invalid row (too many columns)

### [100.1.0] - 2016-07-04

  * Magento 2.1 compatibility

### [100.0.13] - 2016-06-30

  * fix: multi-select attribute options

### [100.0.12] - 2016-06-21

  * fix: Import of Related / Cross-Sell / UP-Sell

### [100.0.11] - 2016-06-21

  * fix: DataSetup Constructor argument error when di is generated
  * fix: use Magento2 factories to avoid bug when launching 2 imports during the same php execution
  * fix: Channel and language Inversion
  * add: Import of Related / Cross-Sell / UP-Sell for products
  * add: management of frontend_model on attribute import
  * add: events on attribute types
  * add: events on product import steps

### [100.0.10] - 2016-06-03

  * add: Website tax class configuration
  * fix: Duplicate label for attributes
  * fix: wrong value for attribute when unit association exists

### [100.0.9] - 2016-06-02
  
  * add: Some documentations (changelog, license, contributing)
  * add: Ability to use full path filename on setFile method, in order to import files that are not in the upload folder.
  * add: New config option Pimgento\General\data_insertion_method with 2 values : "Load Data Infile" and "By 1000 rows"
  * add: Default configurable attribute values configuration (Example: force status to enabled)

### [100.0.8] - 2016-05-20

  * add: Add virtual product support. You can use a column type_id in the product csv file, and using simple/virtual values
  * fix: Fix columns matching (all case) for configurable products
  * fix: Disable the demo module
  * fix: Better readme for compatibility with akeneo 1.5

### [100.0.7] - 2016-05-13

  * fix: Better attribute type mapping for price attribute
  * fix: Init stock infos for configurable product
  * fix: Do not create stock infos per website, but only on the main website.
  * fix: Bad translation
  * fix: Bad default price type

### [100.0.6] - 2016-04-29

  * add: Import directory configuration
  * add: French translation
  * add: Admin ACL
  * fix: Sample data

### [100.0.5] - 2016-04-22

  * add: Command line execution

### [100.0.4] - 2016-04-12

  * fix: Column duplication improvement

### [100.0.3] - 2016-04-12

  * fix: Allow multiple columns matching
  * fix: Match localizable and scopable columns

### [100.0.2] - 2016-04-08

  * add: LOAD DATA INFILE request configuration. You can add LOCAL option to request

### [100.0.1] - 2016-04-06

  * First version of PimGento2 for Magento 2