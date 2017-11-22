PIMGento2 Import
================

About it:
---------

* PIMGento2 allow you to import quickly your catalog from Akeneo to Magento.

* PIMGento2 only accept csv files.

* To prevent errors due to missing data in Magento, you need to import your files in a specific order.

Import Order:
-------------
The following diagram is pretty straightforward for importing your data in order with PIMGento2. You can skip steps, but be careful! For example, if you want to import attribute options and you have newly created attributes, if you don't import them before (even if you don't want to import this options for those missing attributes) it will result in an error. So check your data before importing it!

![pimgento-diagram](PIMGento-M2-diagram.png)

Media import
------------

The media files are imported during the simple product import process.
They must be in a folder *files* in the same folder of the simple product csv file.
You can configure the columns to use in the Magento Catalog Pimgento configuration section.
The value must be exactly the path of the image, relatively to the csv file: files/foo/bar.png

Technical stuff you should know about:
--------------------------------------

 Instead of handling the file line by line, PIMGento2 insert all the data from the file into a temporary table. Then data manipulation (mapping,...) is made within this temporary table in SQL. Finally modified content is directly inserted in SQL in Magento tables.

 Even if  raw SQL insertion is not the way you usually used to import data into a system, it is way more faster than anything else for the moment, especially with the volume of data you can have with a full Akeneo catalog. It results in a significant time saving in your import.
 
Magento staging feature compatibility:
--------------------------------------

Pimgento import is compatible with Magento EE Staging. It has 4 modes for updating products. 
Categories are using the "Update Last Created Stage" mode and can't be configured for now. 

### Staging Modes

- **Update Last Created Stage**
    In this mode pimgento will simply update the last version of the product that exists. 
    It won't change any other versions.

- **Update Current Stage**
    In this mode pimgento will simply update the current version of the product.
    It won't change any other versions.

- **Update All Stages**
    In this mode Pimgento will update all the version/stages of the products. 
    You should use this mode if for example you are handling prices throught the staging in Magento but get the products information from your PIM and don't wish to have different product information on different stages.

- **Full mode**
    In this mode you need to have in your product csv file the additional fields `from` and `to`.   
    Those columns needs to be dates in the following format : `YYYY-MM-DD`.  
    Those fields are going to be used to create new stages for the products during the import.  
       
    There are a few points you need to look for : 
    * You can't update or create stages that has already started. 
    * You can't have 2 stages starting the same day but finishing on a different day (Magento handles this by simply adding a second to the beginning of the stage but pimgento won't handle it.)
    * You can't have different stages on simple products of the same configurable. 
    * You can't edit existing stages. 
      * If you created a stage from 2016-01-20 to the infinity and try to import a new version that starts at the same date it won't allow it. Basically you won't be allowed to create any new stage on this product.
      * If you created a stage from 2016-01-20 to 2017-01-20 you won't be able to create a stage in between. 
    
    If an imported product is new when imported no stage will be created and the `from` and `to` columns will be ignored.