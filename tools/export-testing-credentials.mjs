import fs from 'node:fs/promises';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';

let input = '';
for await (const chunk of process.stdin) input += chunk;
const rows = JSON.parse(input); input = '';
const output = process.argv[2];
const workbook = Workbook.create();
const sheet = workbook.worksheets.add('Employee credentials');
sheet.showGridLines = false;
sheet.getRange('A2').values = [['Employee testing credentials']];
sheet.getRange('A3').values = [['One-time testing handoff. Active accounts can navigate immediately; changing the temporary password is recommended.']];
sheet.getRange('A2').format.font = {name:'Arial',size:14,bold:true};
const headers = ['Employee login ID','Employee number, if different','Employee name','Company','Department','Department level','Effective access','Admin access source','Temporary password','Account status','Employee origin','Company email ID'];
sheet.getRange('A5:L5').values = [headers];
sheet.getRange(`A6:L${rows.length+5}`).values = rows;
sheet.getRange(`A5:L${rows.length+5}`).format.font = {name:'Arial',size:11};
sheet.getRange('A5:L5').format = {fill:'#18354A',font:{name:'Arial',size:11,bold:true,color:'#FFFFFF'},wrapText:true,rowHeight:36};
sheet.getRange(`A6:L${rows.length+5}`).format.rowHeight = 28;
for (const [col,width] of Object.entries({A:240,B:190,C:330,D:210,E:200,F:115,G:125,H:150,I:260,J:275,K:145,L:360})) sheet.getRange(`${col}:${col}`).format.columnWidthPx = width;
sheet.freezePanes.freezeRows(5);
for(let i=0;i<rows.length;i++) {
 if(rows[i][9].startsWith('INACTIVE'))sheet.getRange(`A${i+6}:L${i+6}`).format.fill='#FCE6E6';
 else if(i%2===1)sheet.getRange(`A${i+6}:L${i+6}`).format.fill='#F1F5F8';
}
workbook.recalculate();
// Inspect non-secret columns only. Never print workbook contents or passwords.
await workbook.inspect({kind:'table',range:'Employee credentials!A5:H10',include:'values',tableMaxRows:6,tableMaxCols:8});
// Render a non-secret range for layout review. Passwords are verified separately in memory.
sheet.getRange(`I6:I${rows.length+5}`).values = rows.map(()=>['[hidden in preview]']);
const preview=await workbook.render({sheetName:'Employee credentials',range:`A2:L${rows.length+5}`,scale:1,format:'png'});
await fs.writeFile(output.replace(/\.xlsx$/i,'-preview.png'),new Uint8Array(await preview.arrayBuffer()));
sheet.getRange(`I6:I${rows.length+5}`).values = rows.map(row=>[row[8]]);
workbook.recalculate();
const file=await SpreadsheetFile.exportXlsx(workbook);await file.save(output);
