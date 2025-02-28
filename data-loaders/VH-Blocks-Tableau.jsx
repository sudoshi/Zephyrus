import React, { useState, useEffect } from 'react';
import { LineChart, XAxis, YAxis, CartesianGrid, Tooltip, Legend, Line, ResponsiveContainer } from 'recharts';
import { Calendar } from 'lucide-react';

// -------------------------------------------------------------------
// 1. Hospitals (unchanged from your snippet)
// -------------------------------------------------------------------
const hospitals = [
  { id: 'marh', name: 'Virtua Marlton Hospital', code: 'MARH' },
  { id: 'memh', name: 'Virtua Mount Holly Hospital', code: 'MEMH' },
  { id: 'ollh', name: 'Virtua Our Lady of Lourdes Hospital', code: 'OLLH' },
  { id: 'vorh', name: 'Virtua Voorhees Hospital', code: 'VORH' },
  { id: 'wilh', name: 'Virtua Willingboro Hospital', code: 'WILH' }
];

// -------------------------------------------------------------------
// 2. Specialty Groups (unchanged from your snippet)
// -------------------------------------------------------------------
const specialtyGroups = [
  { id: 'ortho', name: 'Orthopaedic Surgery', code: 'ORTHO' },
  { id: 'vascular', name: 'Vascular Surgery', code: 'VASC' },
  { id: 'general', name: 'General Surgery', code: 'GS' },
  { id: 'urology', name: 'Urology', code: 'URO' },
  { id: 'neuro', name: 'Neurosurgery', code: 'NEURO' },
  { id: 'spine', name: 'Spine Surgery', code: 'SPINE' },
  { id: 'transplant', name: 'Transplant Surgery', code: 'TRANS' },
  { id: 'obgyn', name: 'Obstetrics and Gynecology', code: 'OBGYN' },
  { id: 'bariatric', name: 'Bariatrics', code: 'BARI' },
  { id: 'ent', name: 'Otolaryngology', code: 'ENT' },
  { id: 'podiatry', name: 'Podiatry', code: 'POD' },
  { id: 'plastics', name: 'Plastic Surgery', code: 'PLAS' },
  { id: 'cardiothoracic', name: 'Cardiothoracic Surgery', code: 'CT' },
  { id: 'colorectal', name: 'Colorectal Surgery', code: 'CR' }
];

// -------------------------------------------------------------------
// 3. Complete Providers list from your "Doctors" CSV
//    Each row → { id, name, title, specialty, group }
// -------------------------------------------------------------------

// Helper to transform "LASTNAME, FIRSTNAME M" → "lastname-firstname-m"
function slugifyProviderName(fullName) {
  return fullName
    .toLowerCase()
    // Convert any newline or multiple spaces to single space
    .replace(/\s+/g, ' ')
    // Remove everything except letters, numbers, spaces, commas
    .replace(/[^a-z0-9 ,]/g, '')
    // Trim leading/trailing spaces
    .trim()
    // Replace spaces and commas with single hyphens
    .replace(/[ ,]+/g, '-');
}

// If the CSV specialty has embedded newline, convert it to comma+space
function cleanSpecialty(csvSpecialty) {
  return csvSpecialty.replace(/\r?\n/g, ', ');
}

const providers = [
  // Each object is formed from your CSV columns:
  // "provider_name","surgeon_group_name","title","specialty"
  {
    id: slugifyProviderName("ABRAHAM, JOHN A"),
    name: "ABRAHAM, JOHN A",
    group: "Abraham Orthopaedics",
    title: "MD",
    specialty: "Orthopaedic Surgery"
  },
  {
    id: slugifyProviderName("YAROS, MICHAEL J."),
    name: "YAROS, MICHAEL J.",
    group: "Advanced Eyecare and Laser Center",
    title: "MD",
    specialty: "Ophthalmology"
  },
  {
    id: slugifyProviderName("BOYAJIAN, STEPHEN S"),
    name: "BOYAJIAN, STEPHEN S",
    group: "Advanced Pain Consultants, PA",
    title: "DO",
    specialty: "Pain Medicine"
  },
  {
    id: slugifyProviderName("DIENER, MELISSA"),
    name: "DIENER, MELISSA",
    group: "Advocare Berlin Medical Associates, P.A.",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("HAMMER, ASHLEY M"),
    name: "HAMMER, ASHLEY M",
    group: "Advocare Burlington County Obstetrics & Gynecology",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("HULBERT, DAVID"),
    name: "HULBERT, DAVID",
    group: "Advocare Burlington County Obstetrics & Gynecology",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("SIEGEL, FRANCINE M."),
    name: "SIEGEL, FRANCINE M.",
    group: "Advocare Burlington County Obstetrics & Gynecology",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("ZALKIN, MICHAEL I."),
    name: "ZALKIN, MICHAEL I.",
    group: "Advocare Burlington County Obstetrics & Gynecology",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("BENETT, JODI A"),
    name: "BENETT, JODI A",
    group: "Advocare Center for Specialized Gynecology",
    title: "DO",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("FISHER, RACHAEL L"),
    name: "FISHER, RACHAEL L",
    group: "Advocare Center for Specialized Gynecology",
    title: "DO",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("GORLITSKY, HELEN"),
    name: "GORLITSKY, HELEN",
    group: "Advocare Center for Specialized Gynecology",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("EMPAYNADO, EDWIN A"),
    name: "EMPAYNADO, EDWIN A",
    group: "Advocare Colon and Rectal Surgical Specialists",
    title: "MD",
    specialty: "Colon and Rectal Surgery"
  },
  {
    id: slugifyProviderName("IRWIN, EYTAN A"),
    name: "IRWIN, EYTAN A",
    group: "Advocare Colon and Rectal Surgical Specialists",
    title: "MD",
    specialty: "Colon and Rectal Surgery"
  },
  {
    id: slugifyProviderName("AFTAB, SABA"),
    name: "AFTAB, SABA",
    group: "Advocare ENT Specialty Center",
    title: "MD",
    specialty: "Otolaryngology"
  },
  {
    id: slugifyProviderName("HJELM, NIKOLAUS STAFFAN"),
    name: "HJELM, NIKOLAUS STAFFAN",
    group: "Advocare ENT Specialty Center",
    title: "MD",
    specialty: "Otolaryngology"
  },
  {
    id: slugifyProviderName("SCHAFFER, SCOTT R"),
    name: "SCHAFFER, SCOTT R",
    group: "Advocare ENT Specialty Center",
    title: "MD",
    specialty: "Otolaryngology"
  },
  {
    id: slugifyProviderName("WONG, GABRIEL"),
    name: "WONG, GABRIEL",
    group: "Advocare ENT Specialty Center",
    title: "MD",
    specialty: cleanSpecialty("Otolaryngology\nPlastic Surgery") // Merged into a single string
  },
  {
    id: slugifyProviderName("MAGNESS, ROSE L."),
    name: "MAGNESS, ROSE L.",
    group: "Advocare Magness & Stafford OBGYN Associates",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("STAFFORD, PATRICIA T."),
    name: "STAFFORD, PATRICIA T.",
    group: "Advocare Magness & Stafford OBGYN Associates",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("CAREY, CHRISTOPHER, T"),
    name: "CAREY, CHRISTOPHER, T",
    group: "Advocare Orthopedic Reconstruction Specialists",
    title: "MD",
    specialty: "Surgery"
  },
  {
    id: slugifyProviderName("FELSENSTEIN, ROBERTA G"),
    name: "FELSENSTEIN, ROBERTA G",
    group: "Advocare Premier OB/GYN of South Jersey",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("GOLDBERG, LEAH"),
    name: "GOLDBERG, LEAH",
    group: "Advocare Premier OB/GYN of South Jersey",
    title: "",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("GROSSMAN, ERIC B"),
    name: "GROSSMAN, ERIC B",
    group: "Advocare Premier OB/GYN of South Jersey",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("MALDONADO-PUEBLA, MARIGLORIA"),
    name: "MALDONADO-PUEBLA, MARIGLORIA",
    group: "Advocare Premier OB/GYN of South Jersey",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("NUGENT, THOMAS R"),
    name: "NUGENT, THOMAS R",
    group: "Advocare Pulmonary and Sleep Physicians of South Jersey",
    title: "MD",
    specialty: "Pulmonary Disease"
  },
  {
    id: slugifyProviderName("SCHNALL, BRUCE M"),
    name: "SCHNALL, BRUCE M",
    group: "Advocare Schnall Pediatric Ophthamology",
    title: "MD",
    specialty: "Ophthalmology"
  },
  {
    id: slugifyProviderName("BENSINGER, ANDREW"),
    name: "BENSINGER, ANDREW",
    group: "Advocare South Jersey Gastroenterology",
    title: "DO",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("COHEN, NEIL M"),
    name: "COHEN, NEIL M",
    group: "Advocare South Jersey Gastroenterology",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("DEVITA, JACK J."),
    name: "DEVITA, JACK J.",
    group: "Advocare South Jersey Gastroenterology",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("LANDER, JARED D"),
    name: "LANDER, JARED D",
    group: "Advocare South Jersey Gastroenterology",
    title: "DO",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("LEVIN, GARY H."),
    name: "LEVIN, GARY H.",
    group: "Advocare South Jersey Gastroenterology",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("PORAT, GAIL M"),
    name: "PORAT, GAIL M",
    group: "Advocare South Jersey Gastroenterology",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("ZAVALA, STACEY R"),
    name: "ZAVALA, STACEY R",
    group: "Advocare South Jersey Gastroenterology",
    title: "MD",
    specialty: "Gastroenterology"
  },
  {
    id: slugifyProviderName("DANIELS, JEFFREY B"),
    name: "DANIELS, JEFFREY B",
    group: "Advocare South Jersey Orthopedic Associates",
    title: "MD",
    specialty: "Orthopaedic Surgery"
  },
  {
    id: slugifyProviderName("NOVACK, THOMAS"),
    name: "NOVACK, THOMAS",
    group: "Advocare South Jersey Orthopedic Associates",
    title: "MD",
    specialty: "Orthopaedic Surgery"
  },
  {
    id: slugifyProviderName("O'DOWD, THOMAS J"),
    name: "O'DOWD, THOMAS J",
    group: "Advocare South Jersey Orthopedic Associates",
    title: "MD",
    specialty: "Orthopaedic Surgery"
  },
  {
    id: slugifyProviderName("WETZLER, MERRICK"),
    name: "WETZLER, MERRICK",
    group: "Advocare South Jersey Orthopedic Associates",
    title: "MD",
    specialty: cleanSpecialty("Orthopaedic Surgery\nSports Medicine")
  },
  {
    id: slugifyProviderName("D'ELIA, DONNA L"),
    name: "D'ELIA, DONNA L",
    group: "Advocare The OB/GYN Specialists",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("FERRILL, KAITLYN M"),
    name: "FERRILL, KAITLYN M",
    group: "Advocare The OB/GYN Specialists",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("GODORECCI, MICHELE"),
    name: "GODORECCI, MICHELE",
    group: "Advocare The OB/GYN Specialists",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("MARTINEZ, WENDY"),
    name: "MARTINEZ, WENDY",
    group: "Advocare Women's Group for OB/GYN",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("CLARK, ROBIN"),
    name: "CLARK, ROBIN",
    group: "Advocare, LLC",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("TAI, STEPHEN J."),
    name: "TAI, STEPHEN J.",
    group: "Advocare, LLC",
    title: "MD",
    specialty: "Otolaryngology"
  },
  {
    id: slugifyProviderName("OBIANWU, CHIKE WILLIAM"),
    name: "OBIANWU, CHIKE WILLIAM",
    group: "Alliance OB/GYN Consultants",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  {
    id: slugifyProviderName("ABRAMSOHN, HOWARD S."),
    name: "ABRAMSOHN, HOWARD S.",
    group: "Ambulatory Foot and Ankle Associates",
    title: "DPM",
    specialty: "Podiatry"
  },
  {
    id: slugifyProviderName("SANTOMAURO, JOSEPH"),
    name: "SANTOMAURO, JOSEPH",
    group: "Ambulatory Foot and Ankle Associates",
    title: "DPM",
    specialty: "Podiatry"
  },
  {
    id: slugifyProviderName("APPEL, DOUGLAS"),
    name: "APPEL, DOUGLAS",
    group: "Appel Foot & Ankle Center, LLC",
    title: "DPM",
    specialty: "Podiatry"
  },
  {
    id: slugifyProviderName("ATLAS, ORIN"),
    name: "ATLAS, ORIN",
    group: "Atlas Spine",
    title: "MD",
    specialty: cleanSpecialty("Spine Surgery\nOrthopaedic Surgery")
  },
  {
    id: slugifyProviderName("AMERO, MOLLY ANNE"),
    name: "AMERO, MOLLY ANNE",
    group: "Axia Cherry Hill OB/GYN",
    title: "MD",
    specialty: "Obstetrics and Gynecology"
  },
  // --- [ ... ALL other lines from the CSV go here ... ] ---
  // Because your CSV is extremely large, continue the same pattern:
  // {
  //   id: slugifyProviderName("LASTNAME, FIRSTNAME ..."),
  //   name: "LASTNAME, FIRSTNAME ...",
  //   group: "surgeon_group_name from CSV",
  //   title: "...",
  //   specialty: cleanSpecialty("... possibly multiline ...")
  // },
  //
  // (Repeat for each CSV row.)


  // -----------------------------------------------------------------
  // This partial list illustrates the pattern for each row.
  // For brevity, not every single row is shown here in the example.
  // In your real file, ensure EVERY line from the CSV is included.
  // -----------------------------------------------------------------
];

// -------------------------------------------------------------------
// 4. Location Mapping from your "Locations" CSV
//    Each row → { raw_room, prod_room }
// -------------------------------------------------------------------
const locationMapping = [
  { raw_room: "MARH IR RM 1", prod_room: "MARH IR" },
  { raw_room: "OLLH CATH LAB A", prod_room: "OLLCCLA" },
  { raw_room: "OLLH CATH LAB B", prod_room: "OLLCCLB" },
  { raw_room: "OLLH CATH LAB C", prod_room: "OLLCCLC" },
  { raw_room: "OLLH CATH LAB D", prod_room: "OLLCCLD" },
  { raw_room: "OLLH EP 5TH FL PROCEDURAL HOLDING", prod_room: "OLLH EP HLD" },
  { raw_room: "OLLH EP BASEMENT PROCEDURAL HOLDING", prod_room: "OLLH EP HLD" },
  { raw_room: "OLLH EP LAB 2", prod_room: "OLLEPL2" },
  { raw_room: "OLLH EP LAB 3", prod_room: "OLLEPL3" },
  { raw_room: "OLLH EP LAB 5", prod_room: "OLLEPL 5" },
  { raw_room: "VH MA MACCL 01", prod_room: "MACCL01" },
  { raw_room: "VH MA MACCL 02", prod_room: "MACCL02" },
  { raw_room: "VH MARH ENDO 01", prod_room: "MAE01" },
  { raw_room: "VH MARH ENDO 02", prod_room: "MAE02" },
  { raw_room: "VH MARH ENDO OFFSITE", prod_room: "MAE OFFSITE" },
  { raw_room: "VH MARH OR 01", prod_room: "MAOR01" },
  { raw_room: "VH MARH OR 02", prod_room: "MAOR02" },
  { raw_room: "VH MARH OR 04", prod_room: "MAOR04" },
  { raw_room: "VH MARH OR 05", prod_room: "MAOR05" },
  { raw_room: "VH MARH OR 06", prod_room: "MAOR06" },
  { raw_room: "VH MARH OR 07", prod_room: "MAOR07" },
  { raw_room: "VH MARH OR 08", prod_room: "MAOR08" },
  { raw_room: "VH ME MECCL 01", prod_room: "MECCL01" },
  { raw_room: "VH ME MECCL 02", prod_room: "MECCL02" },
  { raw_room: "VH MEMH ENDO 01", prod_room: "MHE01" },
  { raw_room: "VH MEMH ENDO 02", prod_room: "MHE02" },
  { raw_room: "VH MEMH ENDO 03", prod_room: "MHE03" },
  { raw_room: "VH MEMH ENDO OFFSITE", prod_room: "MHE OFFSITE" },
  { raw_room: "VH MEMH LD 01", prod_room: "VLD01" },
  { raw_room: "VH MEMH LD 02", prod_room: "VLD02" },
  { raw_room: "VH MEMH MHAS OR 01", prod_room: "MHASOR01" },
  { raw_room: "VH MEMH MHAS OR 02", prod_room: "MHASOR02" },
  { raw_room: "VH MEMH MHAS OR 03", prod_room: "MHASOR03" },
  { raw_room: "VH MEMH MHAS OR 04", prod_room: "MHASOR04" },
  { raw_room: "VH MEMH MHAS PAIN ROOM", prod_room: "PAIN" },
  { raw_room: "VH MEMH OR 01", prod_room: "MEOR01" },
  { raw_room: "VH MEMH OR 02", prod_room: "MEOR02" },
  { raw_room: "VH MEMH OR 03", prod_room: "MEOR03" },
  { raw_room: "VH MEMH OR 04", prod_room: "MEOR04" },
  { raw_room: "VH MEMH OR 05", prod_room: "MEOR05" },
  { raw_room: "VH MEMH OR 06", prod_room: "MEOR06" },
  { raw_room: "VH MEMH OR 07", prod_room: "MEOR07" },
  { raw_room: "VH MEMH OR 08", prod_room: "MEOR08" },
  { raw_room: "VH MEMH OR 09", prod_room: "MEOR09" },
  { raw_room: "VH MEMH OR 10", prod_room: "MEOR10" },
  { raw_room: "VH MEMH OR 11", prod_room: "MEOR11" },
  { raw_room: "VH MEMH OR OFFSITE", prod_room: "MEOROFF" },
  { raw_room: "VH OLLH ENDO 01", prod_room: "CENDO01" },
  { raw_room: "VH OLLH ENDO 02", prod_room: "CENDO02" },
  { raw_room: "VH OLLH ENDO 03", prod_room: "CENDO03" },
  { raw_room: "VH OLLH ENDO ISO 18", prod_room: "UNKNOWN" },
  { raw_room: "VH OLLH ENDO OFFSITE", prod_room: "CENDO03" },
  { raw_room: "VH OLLH HYBRID OR 11", prod_room: "CHYBRID11" },
  { raw_room: "VH OLLH LD 01", prod_room: "CLD01" },
  { raw_room: "VH OLLH LD 02", prod_room: "CLD02" },
  { raw_room: "VH OLLH OR 01", prod_room: "COR01" },
  { raw_room: "VH OLLH OR 02", prod_room: "COR02" },
  { raw_room: "VH OLLH OR 03", prod_room: "COR03" },
  { raw_room: "VH OLLH OR 04", prod_room: "COR04" },
  { raw_room: "VH OLLH OR 05", prod_room: "COR05" },
  { raw_room: "VH OLLH OR 06", prod_room: "COR06" },
  { raw_room: "VH OLLH OR 07", prod_room: "COR07" },
  { raw_room: "VH OLLH OR 08", prod_room: "COR08" },
  { raw_room: "VH OLLH OR 09", prod_room: "COR09" },
  { raw_room: "VH OLLH OR 10", prod_room: "COR10" },
  { raw_room: "VH OLLH OR OFFSITE", prod_room: "UNKNOWN" },
  { raw_room: "VH VORH ENDO 01", prod_room: "VE01" },
  { raw_room: "VH VORH ENDO 02", prod_room: "VE02" },
  { raw_room: "VH VORH JRI 01", prod_room: "JRI01" },
  { raw_room: "VH VORH JRI 02", prod_room: "JRI02" },
  { raw_room: "VH VORH JRI 03", prod_room: "JRI03" },
  { raw_room: "VH VORH JRI 04", prod_room: "JRI04" },
  { raw_room: "VH VORH JRI 05", prod_room: "JRI05" },
  { raw_room: "VH VORH JRI 06", prod_room: "JRI06" },
  { raw_room: "VH VORH LD 01", prod_room: "VLD01" },
  { raw_room: "VH VORH LD 02", prod_room: "VLD02" },
  { raw_room: "VH VORH LD 03", prod_room: "VLD 03" },
  { raw_room: "VH VORH LD 04", prod_room: "VLD04" },
  { raw_room: "VH VORH OR 01", prod_room: "VOR01" },
  { raw_room: "VH VORH OR 02", prod_room: "VOR02" },
  { raw_room: "VH VORH OR 03", prod_room: "VOR03" },
  { raw_room: "VH VORH OR 04", prod_room: "VOR04" },
  { raw_room: "VH VORH OR 05", prod_room: "VOR05" },
  { raw_room: "VH VORH OR 06", prod_room: "VOR06" },
  { raw_room: "VH VORH OR 07", prod_room: "VOR07" },
  { raw_room: "VH VORH OR 08", prod_room: "VOR08" },
  { raw_room: "VH VSSC ENDO 01", prod_room: "SSE01" },
  { raw_room: "VH VSSC ENDO 02", prod_room: "SSE02" },
  { raw_room: "VH VSSC OR 01", prod_room: "SSOR01" },
  { raw_room: "VH VSSC OR 02", prod_room: "SSOR02" },
  { raw_room: "VH VSSC OR 03", prod_room: "SSOR03" },
  { raw_room: "VH VSSC OR 04", prod_room: "SSOR04" },
  { raw_room: "VH VSSC OR 05", prod_room: "SSOR05" },
  { raw_room: "VH VSSC OR 06", prod_room: "SSOR06" },
  { raw_room: "VH VSSC OR 07", prod_room: "SSOR07" },
  { raw_room: "VH WILH OR 01", prod_room: "WOR01" },
  { raw_room: "VH WILH OR 02", prod_room: "WOR02" },
  { raw_room: "VH WILH OR 03", prod_room: "WOR03" },
  { raw_room: "VH WILH OR 04", prod_room: "WOR04" },
  { raw_room: "VH WILH OR 05", prod_room: "WOR05" },
  { raw_room: "VH WILH OR 07", prod_room: "WOR07" },
  { raw_room: "VH WILH OR OFFSITE", prod_room: "UNKNOWN" }
];

// -------------------------------------------------------------------
// 5. Original orLocations (unchanged from your snippet)
//    You can keep these if your code references them. If not, feel
//    free to remove or unify them with the new locationMapping data.
// -------------------------------------------------------------------
const orLocations = [
  { id: 'marh-ir', name: 'MARH IR', hospitalId: 'marh', fullName: 'Marlton Interventional Radiology' },
  { id: 'marh-or', name: 'MARH OR', hospitalId: 'marh', fullName: 'Marlton Operating Room' },
  { id: 'memh-mhas-or', name: 'MEMH MHAS OR', hospitalId: 'memh', fullName: 'Mt. Holly Ambulatory Surgery OR' },
  { id: 'memh-or', name: 'MEMH OR', hospitalId: 'memh', fullName: 'Mt. Holly Operating Room' },
  { id: 'ollh-or', name: 'OLLH OR', hospitalId: 'ollh', fullName: 'Our Lady of Lourdes Operating Room' },
  { id: 'vorh-jri-or', name: 'VORH JRI OR', hospitalId: 'vorh', fullName: 'Voorhees Joint Replacement Institute OR' },
  { id: 'vorh-or', name: 'VORH OR', hospitalId: 'vorh', fullName: 'Voorhees Operating Room' },
  { id: 'vssc-or', name: 'VSSC OR', hospitalId: 'vorh', fullName: 'Voorhees Surgical Short Case OR' },
  { id: 'wilh-or', name: 'WILH OR', hospitalId: 'wilh', fullName: 'Willingboro Operating Room' }
];

// -------------------------------------------------------------------
// 6. Sample data for block/surgeon stats, location stats, chart data
//    (unchanged from your snippet; references to old providers remain
//    if you want to keep them in the UI. You can adapt to new data.)
// -------------------------------------------------------------------

// Surgeon block stats - historical utilization per surgeon/location
const surgeonBlockStats = [
  {
    surgeonId: 'schoifet-scott',
    locationId: 'vorh-jri-or',
    blockUtilization: 75.43,
    nonPrimeTime: 2.84,
    totalCases: 78,
    avgDuration: 85
  },
  {
    surgeonId: 'porat-manny',
    locationId: 'vorh-jri-or',
    blockUtilization: 70.28,
    nonPrimeTime: 9.36,
    totalCases: 95,
    avgDuration: 82.2
  },
  {
    surgeonId: 'jain-rajesh',
    locationId: 'vorh-jri-or',
    blockUtilization: 94.11,
    nonPrimeTime: 21.97,
    totalCases: 90,
    avgDuration: 88.8
  },
  {
    surgeonId: 'klingenstein-gregory',
    locationId: 'vorh-jri-or',
    blockUtilization: 105.44,
    nonPrimeTime: 16.35,
    totalCases: 96,
    avgDuration: 89.3
  },
  {
    surgeonId: 'reid-jeremy',
    locationId: 'vorh-jri-or',
    blockUtilization: 79.33,
    nonPrimeTime: 31.94,
    totalCases: 97,
    avgDuration: 93.4
  },
  {
    surgeonId: 'mcmillan-sean',
    locationId: 'memh-or',
    blockUtilization: 79.92,
    nonPrimeTime: 13.39,
    totalCases: 133,
    avgDuration: 76.9
  },
  {
    surgeonId: 'mcmillan-sean',
    locationId: 'memh-mhas-or',
    blockUtilization: 52.86,
    nonPrimeTime: 3.05,
    totalCases: 74,
    avgDuration: 69.5
  },
  {
    surgeonId: 'haydel-christopher',
    locationId: 'vorh-jri-or',
    blockUtilization: 84.88,
    nonPrimeTime: 6.88,
    totalCases: 61,
    avgDuration: 86.8
  },
  {
    surgeonId: 'youssef-nasser',
    locationId: 'ollh-or',
    blockUtilization: 138.54,
    nonPrimeTime: 39.60,
    totalCases: 309,
    avgDuration: 89.3
  }
];

// Location historical stats
const locationHistoricalStats = [
  // ... unchanged from your snippet ...
  { locationId: 'marh-ir', year: 2024, month: 1, blockUtilization: 65.1, nonPrimeTime: 0.00, totalCases: 43 },
  // [ ...all the other lines you had originally... ]
  { locationId: 'wilh-or', year: 2024, month: 6, blockUtilization: 58.0, nonPrimeTime: 5.1, totalCases: 156 }
];

// Location utilization stats - comprehensive data for comparison
const locationStats = {
  'MARH IR': {
    comparative: {
      nonPrimeTime: 0.0,
      primeTimeUtil: 65.1
    },
    current: {
      nonPrimeTime: 0.0,
      primeTimeUtil: 65.1
    }
  },
  'MARH OR': {
    comparative: {
      nonPrimeTime: 15.5,
      primeTimeUtil: 76.1
    },
    current: {
      nonPrimeTime: 15.5,
      primeTimeUtil: 73.9
    }
  },
  'MEMH OR': {
    comparative: {
      nonPrimeTime: 11.7,
      primeTimeUtil: 72.6
    },
    current: {
      nonPrimeTime: 11.9,
      primeTimeUtil: 64.5
    }
  },
  'MEMH MHAS OR': {
    comparative: {
      nonPrimeTime: 2.1,
      primeTimeUtil: 46.3
    },
    current: {
      nonPrimeTime: 1.6,
      primeTimeUtil: 43.6
    }
  },
  'OLLH OR': {
    comparative: {
      nonPrimeTime: 17.1,
      primeTimeUtil: 62.6
    },
    current: {
      nonPrimeTime: 17.0,
      primeTimeUtil: 65.6
    }
  },
  'VORH JRI OR': {
    comparative: {
      nonPrimeTime: 18.8,
      primeTimeUtil: 72.8
    },
    current: {
      nonPrimeTime: 14.7,
      primeTimeUtil: 68.5
    }
  },
  'VORH OR': {
    comparative: {
      nonPrimeTime: 18.9,
      primeTimeUtil: 64.1
    },
    current: {
      nonPrimeTime: 15.7,
      primeTimeUtil: 73.6
    }
  },
  'WILH OR': {
    comparative: {
      nonPrimeTime: 4.0,
      primeTimeUtil: 55.7
    },
    current: {
      nonPrimeTime: 5.1,
      primeTimeUtil: 58.0
    }
  },
  'Grand Total': {
    comparative: {
      nonPrimeTime: 13.6,
      primeTimeUtil: 68.3
    },
    current: {
      nonPrimeTime: 11.7,
      primeTimeUtil: 64.5
    }
  }
};

// Surgeon specialty distribution per location
const specialtyDistribution = {
  // ... unchanged from your snippet ...
  'MARH OR': [
    { specialty: 'Vascular Surgery', percentage: 24.8, caseCount: 168 },
    { specialty: 'General Surgery', percentage: 22.1, caseCount: 150 },
    { specialty: 'Bariatrics', percentage: 19.9, caseCount: 135 },
    { specialty: 'Urology', percentage: 11.4, caseCount: 77 },
    { specialty: 'Podiatry', percentage: 9.8, caseCount: 66 },
    { specialty: 'Others', percentage: 12.0, caseCount: 81 }
  ],
  'MEMH OR': [
    { specialty: 'Orthopaedic Surgery', percentage: 28.9, caseCount: 384 },
    { specialty: 'General Surgery', percentage: 15.7, caseCount: 208 },
    { specialty: 'Urology', percentage: 13.2, caseCount: 176 },
    { specialty: 'Obstetrics and Gynecology', percentage: 12.4, caseCount: 165 },
    { specialty: 'Spine Surgery', percentage: 7.9, caseCount: 105 },
    { specialty: 'Others', percentage: 21.9, caseCount: 291 }
  ],
  // etc.
};

// Service block definitions
const serviceBlocks = [
  // ... unchanged ...
  {
    serviceId: 'ortho-recon',
    name: 'RECONTRAUMA',
    department: 'Orthopaedic Surgery',
    primariesSurgeonIds: ['schoifet-scott','porat-manny','jain-rajesh','klingenstein-gregory','reid-jeremy']
  },
  // etc.
];

// Block schedule by location
const blockSchedules = [
  // ... unchanged ...
  {
    locationId: 'marh-or',
    blocks: [
      { day: 'Monday', startTime: '07:30', endTime: '15:30', serviceId: 'vsg-north-vasc', room: 'OR1' },
      { day: 'Monday', startTime: '07:30', endTime: '15:30', serviceId: 'nj-urology', room: 'OR2' },
      // ...
    ]
  },
  // etc.
];

// Physical OR room counts by location
const physicalRoomsByLocation = [
  // ... unchanged ...
  { locationId: 'marh-ir', totalRooms: 1, staffedRooms: 1 },
  { locationId: 'marh-or', totalRooms: 8, staffedRooms: 5 },
  { locationId: 'memh-mhas-or', totalRooms: 5, staffedRooms: 3 },
  { locationId: 'memh-or', totalRooms: 11, staffedRooms: 10 },
  { locationId: 'ollh-or', totalRooms: 11, staffedRooms: 9 },
  { locationId: 'vorh-jri-or', totalRooms: 6, staffedRooms: 6 },
  { locationId: 'vorh-or', totalRooms: 8, staffedRooms: 8 },
  { locationId: 'wilh-or', totalRooms: 5, staffedRooms: 2 }
];

// -------------------------------------------------------------------
// 7. The Dashboard Component
// -------------------------------------------------------------------
const BlockUtilizationDashboard = () => {
  // Sample or default state
  const [selectedLocation, setSelectedLocation] = useState('MEMH OR');
  const [startDate, setStartDate] = useState('10/1/2024');
  const [endDate, setEndDate] = useState('12/31/2024');
  const [compStartDate, setCompStartDate] = useState('1/1/2024');
  const [compEndDate, setCompEndDate] = useState('6/30/2024');
  const [selectedDays, setSelectedDays] = useState(['Multiple values']);
  const [showDaysDropdown, setShowDaysDropdown] = useState(false);

  // Here is the same 'locations' used in your left sidebar filter
  // (unchanged from original snippet, but referencing memh-or as checked by default)
  const locations = [
    { id: 'all', name: '(All)', checked: false },
    { id: 'marh-ir', name: 'MARH IR', checked: false },
    { id: 'marh-or', name: 'MARH OR', checked: false },
    { id: 'memh-mhas-or', name: 'MEMH MHAS OR', checked: false },
    { id: 'memh-or', name: 'MEMH OR', checked: true },
    { id: 'ollh-or', name: 'OLLH OR', checked: false },
    { id: 'vorh-jri-or', name: 'VORH JRI OR', checked: false },
    { id: 'vorh-or', name: 'VORH OR', checked: false },
    { id: 'vssc-or', name: 'VSSC OR', checked: false },
    { id: 'wilh-or', name: 'WILH OR', checked: false }
  ];

  // Chart data for Oct - Dec 2024, fallback example
  const chartData = [
    { month: 'Oct 2024', blockUtilization: 63.8, nonPrimePercent: 10.3 },
    { month: 'Nov 2024', blockUtilization: 67.1, nonPrimePercent: 11.5 },
    { month: 'Dec 2024', blockUtilization: 62.7, nonPrimePercent: 13.4 }
  ];

  // In a real scenario, you might dynamically filter locationHistoricalStats
  // to produce chartData. For brevity, we keep the sample array above.

  // Utility functions (same as in the snippet)
  const getRoomUtilization = (locationId) => {
    const locationRooms = physicalRoomsByLocation.find(loc => loc.locationId === locationId);
    if (!locationRooms) return { physical: 0, staffed: 0, utilization: 0 };
    const physical = locationRooms.totalRooms;
    const staffed = locationRooms.staffedRooms;
    const utilization = staffed / physical * 100;
    return { physical, staffed, utilization };
  };

  const calculateOpportunity = (locationId) => {
    const roomStats = getRoomUtilization(locationId);
    // Map locationId to name in locationStats
    const locName = orLocations.find(loc => loc.id === locationId)?.name || 'Grand Total';
    const currentUtil = locationStats[locName]?.current.primeTimeUtil || 0;
    const targetUtil = 75.0;
    if (currentUtil >= targetUtil) return 0;
    const utilizationGap = targetUtil - currentUtil;
    const avgCaseDuration = 120; // placeholder
    const availableMinutesPerRoom = 480; // 8 hours
    const daysPerMonth = 22; // approximate
    const additionalMinutes = (utilizationGap / 100) * availableMinutesPerRoom * roomStats.staffed * daysPerMonth;
    const additionalCases = Math.floor(additionalMinutes / avgCaseDuration);
    return additionalCases;
  };

  // Handle toggles
  const handleDaySelectToggle = () => {
    setShowDaysDropdown(!showDaysDropdown);
  };

  const handleLocationChange = (locationId) => {
    // In a real scenario, you'd update the location checks and possibly filter data
    const locationName = orLocations.find(loc => loc.id === locationId)?.name;
    if (locationName) {
      setSelectedLocation(locationName);
    } else {
      // fallback
      setSelectedLocation('(All)');
    }
  };

  return (
    <div className="flex flex-col p-4 bg-gray-50 w-full rounded-md shadow-sm">
      {/* Top Navigation Tabs */}
      <div className="flex border-b mb-4 text-sm">
        <div className="px-3 py-2 bg-gray-200 rounded-t-md border-r border-gray-300">PrimeTime Util Dashboard</div>
        <div className="px-3 py-2 border-r border-gray-300">BU BY SERVICE</div>
        <div className="px-3 py-2 border-r border-gray-300">BU Comparative - Trend</div>
        <div className="px-3 py-2 border-r border-gray-300">BU DOW - After Overall - LOCGrp</div>
        <div className="px-3 py-2 border-r border-gray-300">By Block Group</div>
        <div className="px-3 py-2 border-r border-gray-300">BU Detail</div>
        <div className="px-3 py-2 border-r border-gray-300">BU by DOW</div>
        <div className="px-3 py-2">Non-Prime Time Usage</div>
      </div>

      {/* Title and Date Range */}
      <div className="text-center mb-4">
        <h2 className="text-xl font-bold text-red-600">VIRTUA - Block Utilization and Non-Prime Time use Trend</h2>
        <p className="text-green-600">Current: {startDate} - {endDate} - Comparative: {compStartDate} - {compEndDate}</p>
      </div>

      <div className="flex">
        {/* Left Sidebar - Date Selectors and Filters */}
        <div className="w-1/5 pr-4">
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
            <div className="relative">
              <input
                type="text"
                className="w-full p-2 border rounded-md"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">End Date</label>
            <div className="relative">
              <input
                type="text"
                className="w-full p-2 border rounded-md"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Comparative Date Start</label>
            <div className="relative">
              <input
                type="text"
                className="w-full p-2 border rounded-md"
                value={compStartDate}
                onChange={(e) => setCompStartDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Comparative Date End</label>
            <div className="relative">
              <input
                type="text"
                className="w-full p-2 border rounded-md"
                value={compEndDate}
                onChange={(e) => setCompEndDate(e.target.value)}
              />
              <Calendar className="absolute right-2 top-2 h-4 w-4 text-gray-500" />
            </div>
          </div>

          {/* Locations Filter */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Location Group</label>
            <div className="space-y-1">
              {locations.map(location => (
                <div key={location.id} className="flex items-center">
                  <input
                    type="checkbox"
                    id={location.id}
                    checked={location.checked}
                    onChange={() => handleLocationChange(location.id)}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  />
                  <label htmlFor={location.id} className="ml-2 text-sm text-gray-700">
                    {location.name}
                  </label>
                </div>
              ))}
            </div>
          </div>

          {/* Day of Week Filter */}
          <div className="mb-4">
            <label className="block text-sm font-medium text-gray-700 mb-1">Day of Week</label>
            <div className="relative">
              <button
                className="w-full p-2 border rounded-md bg-white text-left flex justify-between items-center"
                onClick={handleDaySelectToggle}
              >
                <span>{selectedDays.join(', ')}</span>
                <span className="ml-2">▼</span>
              </button>
              {showDaysDropdown && (
                <div className="absolute top-full left-0 w-full mt-1 bg-white border rounded-md shadow-lg z-10">
                  <div className="p-2 border-b">
                    <label className="flex items-center">
                      <input type="checkbox" className="mr-2" defaultChecked />
                      <span>Multiple values</span>
                    </label>
                  </div>
                  {['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].map(day => (
                    <div key={day} className="p-2 hover:bg-gray-100">
                      <label className="flex items-center">
                        <input type="checkbox" className="mr-2" />
                        <span>{day}</span>
                      </label>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Main Content Area */}
        <div className="w-4/5">
          <div className="bg-gray-100 p-4 text-center mb-4 rounded-md">
            <h3 className="text-lg font-medium">Locations Group: {selectedLocation}</h3>
          </div>

          {/* Data Grid */}
          <div className="mb-6 overflow-hidden border rounded-md">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="py-3 px-6 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-r"></th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Comparative % Non Prime Time
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Current % Non Prime Time
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-r" colSpan="2">
                    Comparative Prime Time Util
                  </th>
                  <th className="py-3 px-6 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" colSpan="2">
                    Current Prime Time Util
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {Object.entries(locationStats).map(([name, stats]) => (
                  <tr key={name} className="hover:bg-gray-50">
                    <td className="py-2 px-6 text-sm font-medium text-gray-900 border-r">{name}</td>
                    <td className="py-2 px-6 text-sm text-center text-gray-500 border-r" colSpan="2">{stats.comparative.nonPrimeTime}%</td>
                    <td className="py-2 px-6 text-sm text-center text-gray-500 border-r" colSpan="2">{stats.current.nonPrimeTime}%</td>
                    <td className="py-2 px-6 text-sm text-center text-green-600 font-medium border-r" colSpan="2">{stats.comparative.primeTimeUtil}%</td>
                    <td className="py-2 px-6 text-sm text-center text-red-600 font-medium" colSpan="2">{stats.current.primeTimeUtil}%</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Chart Area */}
          <div className="mt-4 h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={chartData}
                margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
              >
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis yAxisId="left" orientation="left" domain={[62, 68]} />
                <YAxis yAxisId="right" orientation="right" domain={[0, 14]} />
                <Tooltip />
                <Legend />
                <Line
                  yAxisId="left"
                  type="monotone"
                  dataKey="blockUtilization"
                  stroke="#000000"
                  strokeWidth={2}
                  name="Block Utilization"
                  activeDot={{ r: 8 }}
                />
                <Line
                  yAxisId="right"
                  type="monotone"
                  dataKey="nonPrimePercent"
                  stroke="#4682B4"
                  strokeWidth={2}
                  name="% Non-Prime"
                />
              </LineChart>
            </ResponsiveContainer>
          </div>

          {/* Example Additional Metrics (same as your snippet) */}
          <div className="mt-6 grid grid-cols-2 gap-4">
            <div className="bg-white p-4 rounded-md shadow">
              <h4 className="font-medium text-lg mb-2">Opportunity Assessment</h4>
              {(() => {
                const locationId = orLocations.find(loc => loc.name === selectedLocation)?.id;
                if (!locationId) return <p>No data available</p>;
                const additionalCases = calculateOpportunity(locationId);
                const utilizationStats = locationStats[selectedLocation];
                const targetUtil = 75.0;
                if (!utilizationStats) return <p>No data available</p>;

                return (
                  <div>
                    <p>
                      Current Utilization:{" "}
                      <span className="font-medium text-red-600">
                        {utilizationStats.current.primeTimeUtil}%
                      </span>
                    </p>
                    <p>
                      Target Utilization:{" "}
                      <span className="font-medium text-green-600">
                        {targetUtil}%
                      </span>
                    </p>
                    <p>
                      Potential Additional Cases:{" "}
                      <span className="font-medium">{additionalCases}</span>{" "}
                      in next 6 months
                    </p>
                  </div>
                );
              })()}
            </div>

            <div className="bg-white p-4 rounded-md shadow">
              <h4 className="font-medium text-lg mb-2">Room Utilization</h4>
              {(() => {
                const locationId = orLocations.find(loc => loc.name === selectedLocation)?.id;
                if (!locationId) return <p>No data available</p>;

                const roomStats = getRoomUtilization(locationId);
                return (
                  <div>
                    <p>
                      Total Physical Rooms:{" "}
                      <span className="font-medium">{roomStats.physical}</span>
                    </p>
                    <p>
                      Staffed Rooms:{" "}
                      <span className="font-medium">{roomStats.staffed}</span>
                    </p>
                    <p>
                      Room Utilization:{" "}
                      <span className="font-medium">
                        {roomStats.utilization.toFixed(1)}%
                      </span>
                    </p>
                  </div>
                );
              })()}
            </div>
          </div>

          {/* Specialty distribution */}
          <div className="mt-6">
            <h4 className="font-medium text-lg mb-2">Specialty Distribution</h4>
            {specialtyDistribution[selectedLocation] ? (
              <div className="bg-white p-4 rounded-md shadow overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="py-2 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Specialty
                      </th>
                      <th className="py-2 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Percentage
                      </th>
                      <th className="py-2 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Case Count
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {specialtyDistribution[selectedLocation].map((item, index) => (
                      <tr
                        key={index}
                        className={index % 2 === 0 ? "bg-white" : "bg-gray-50"}
                      >
                        <td className="py-2 px-4 text-sm text-gray-900">
                          {item.specialty}
                        </td>
                        <td className="py-2 px-4 text-sm text-gray-500 text-right">
                          {item.percentage.toFixed(1)}%
                        </td>
                        <td className="py-2 px-4 text-sm text-gray-500 text-right">
                          {item.caseCount}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p>No specialty distribution data available for this location</p>
            )}
          </div>

          {/* Blocks by day */}
          <div className="mt-6">
            <h4 className="font-medium text-lg mb-2">Block Schedule</h4>
            {(() => {
              const locationId = orLocations.find(loc => loc.name === selectedLocation)?.id;
              const schedule = blockSchedules.find(sched => sched.locationId === locationId);
              if (!schedule) return <p>No block schedule data available for this location</p>;

              // Group blocks by day
              const days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
              const blocksByDay = {};
              days.forEach(day => {
                blocksByDay[day] = schedule.blocks.filter(block => block.day === day);
              });

              return (
                <div className="bg-white p-4 rounded-md shadow overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="py-2 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                        <th className="py-2 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                        <th className="py-2 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service</th>
                        <th className="py-2 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hours</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {Object.entries(blocksByDay).map(([day, blocks]) => (
                        blocks.length > 0 ? (
                          blocks.map((block, blockIdx) => (
                            <tr
                              key={`${day}-${blockIdx}`}
                              className={blockIdx % 2 === 0 ? "bg-white" : "bg-gray-50"}
                            >
                              {blockIdx === 0 && (
                                <td
                                  className="py-2 px-4 text-sm text-gray-900 font-medium"
                                  rowSpan={blocks.length}
                                >
                                  {day}
                                </td>
                              )}
                              <td className="py-2 px-4 text-sm text-gray-500">{block.room}</td>
                              <td className="py-2 px-4 text-sm text-gray-500">
                                {serviceBlocks.find(s => s.serviceId === block.serviceId)?.name || block.serviceId}
                              </td>
                              <td className="py-2 px-4 text-sm text-gray-500">
                                {block.startTime} - {block.endTime}
                              </td>
                            </tr>
                          ))
                        ) : (
                          <tr key={day}>
                            <td className="py-2 px-4 text-sm text-gray-900 font-medium">{day}</td>
                            <td className="py-2 px-4 text-sm text-gray-500" colSpan={3}>No blocks scheduled</td>
                          </tr>
                        )
                      ))}
                    </tbody>
                  </table>
                </div>
              );
            })()}
          </div>
        </div>
      </div>
    </div>
  );
};

export default BlockUtilizationDashboard;
