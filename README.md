# tiny-database

#how to use

1. require 'database.php'
2. pass in your database setting through DB::setup()
3. enjoy it

#query data

1. table($name)->all() to get all results from a table
2. table($name)->find($id) to get a specifiy results
3. table($name)->whereName($value)->get() 
