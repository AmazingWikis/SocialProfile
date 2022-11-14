#! /bin/bash

echo "What is the wiki's database name?"
read database_name

mysql -u root -e "USE $database_name; DROP TABLE user_fields_privacy;"
mysql -u root -e "USE $database_name; DROP TABLE user_profile;"
mysql -u root -e "USE $database_name; DROP TABLE user_board;"
mysql -u root -e "USE $database_name; DROP TABLE user_system_messages;"

mysql -u root -e "USE $database_name; DROP TABLE user_system_gift;"
mysql -u root -e "USE $database_name; DROP TABLE system_gift;"
mysql -u root -e "USE $database_name; DROP TABLE user_gift;"
mysql -u root -e "USE $database_name; DROP TABLE gift;"
mysql -u root -e "USE $database_name; DROP TABLE user_relationship_request;"
mysql -u root -e "USE $database_name; DROP TABLE user_relationship;"
mysql -u root -e "USE $database_name; DROP TABLE user_points_archive;"
mysql -u root -e "USE $database_name; DROP TABLE user_points_monthly;"
mysql -u root -e "USE $database_name; DROP TABLE user_points_weekly;"
mysql -u root -e "USE $database_name; DROP TABLE user_stats;"

echo "Done dropping tables"
