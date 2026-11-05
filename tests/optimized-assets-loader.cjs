// Test-only loader: run the existing DOM regressions against transformed assets.
const fs=require('fs'),path=require('path'),read=fs.readFileSync,root=path.resolve(__dirname,'../assets')+path.sep;
fs.readFileSync=function(file,options){if(typeof file==='string'&&path.resolve(file).startsWith(root)){const transformed=path.join('/tmp/crmp-optimized-assets',path.basename(file));if(fs.existsSync(transformed))return read.call(this,transformed,options);}return read.call(this,file,options);};
