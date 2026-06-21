# 03 — Predictive Analytics & Machine Learning for Hospital Operations

**Dossier section for Zephyrus** — the predictive layer of a real-time demand/capacity optimization platform.

This section surveys the state of the art for the seven predictive problems that drive bed, ED, and discharge optimization, plus the production-ML realities and regulatory boundaries that constrain what Zephyrus can ship. Each subsection closes with concrete design implications: which models to build first, the features required, evaluation metrics, governance guardrails, and a build-vs-buy seam strategy. The governing architectural principle throughout is a **pluggable model service**: every predictor sits behind a uniform scoring interface so that an internally trained model, a vendor model (e.g., Epic), or a clinical rule can be swapped without touching the optimization engine.

---

## 1. Census & Occupancy Forecasting

**Problem.** Predict total occupied beds (and beds-by-unit) on horizons from a few hours to ~14 days, so the platform can pre-empt capacity crunches, plan staffing, and trigger surge protocols.

**Methods & evidence.** The literature converges on a clear hierarchy. Classical statistical models — ARIMA/SARIMA/SARIMAX — remain strong, interpretable, and low-data baselines. A Multivariate Seasonal ARIMA (MSARIMA) on NHS Trust data forecast **non-elective bed occupancy and admissions accurately over a 6-week horizon** ([NHS MSARIMA, PMC9021768](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9021768/)). Prophet excels on strongly seasonal series with holiday effects and minimal tuning. Gradient-boosted trees (XGBoost/LightGBM) handle nonlinear interactions and exogenous regressors well. Deep temporal models (LSTM, CNN-LSTM, TCN) win on complex, long-horizon, highly nonlinear patterns but demand more data and engineering. Comparative studies find that **ARIMA is best for simple patterns, Prophet for seasonal data, and LSTM for complex long-term patterns**, with **hybrid ARIMA-LSTM ensembles reaching ~2.4% MAPE**, outperforming any single model ([ARIMA-LSTM comparison](https://www.researchgate.net/publication/387701628_A_Comparative_Study_of_ARIMA_Prophet_and_LSTM_for_Time_Series_Prediction); [hybrid ARIMA-Prophet](https://www.sciencedirect.com/science/article/pii/S2590123025017748)). A Bayesian-model-averaging deep-learning approach to inpatient bed occupancy in mental-health facilities beat ARIMA/SARIMA baselines and, importantly, **quantified forecast uncertainty** ([Nature Sci Rep 2025](https://www.nature.com/articles/s41598-025-22001-6)). Time-series foundation models (TimeGPT) are now being benchmarked for real-world ops forecasting ([TimeGPT benchmark](https://www.sciencedirect.com/science/article/pii/S2666827025001847)).

**Seasonality.** Census exhibits strong weekly (weekday vs. weekend discharge rhythm), annual (winter respiratory surge), and holiday effects. Day-of-week dominates short horizons. Census is also mechanically the integral of admissions minus discharges, so a **flow decomposition** (forecast inflow and outflow separately, then reconcile) is often more accurate and far more actionable than forecasting the net census level directly.

**Design implications for Zephyrus.**
- **Build first:** a SARIMAX/Prophet baseline per unit for the 0–72h horizon, plus a LightGBM model with exogenous regressors (scheduled OR/elective admits, ED-pending admits, day-of-week, holiday, weather, local epidemic signal). Reserve LSTM/TCN for v2 once ≥18–24 months of data exist.
- **Decompose flow:** census = current census + predicted admissions − predicted discharges. Source admissions from §4 and discharges from §2/§3; this makes the census forecast explainable and directly drives optimization levers.
- **Metrics:** MAPE and MAE on census; coverage of prediction intervals (target ~90% PI coverage); calibrated uncertainty is mandatory — a point census forecast without a credible interval is operationally useless for surge triggers.
- **Seam:** census forecaster is a pluggable service consuming the admit/discharge predictors. Ship the statistical baseline as the always-on fallback when ML confidence is low or inputs are stale.

---

## 2. Discharge Prediction / Discharge-Readiness

**Problem.** Identify which inpatients will be discharged **today or tomorrow**, and produce an **estimated discharge date (EDD)** per patient — the single highest-leverage signal for bed turnover and proactive discharge planning.

**Methods & evidence.** XGBoost on structured + unstructured (NLP) EHR features is the workhorse. A model validated and **integrated into the live clinical workflow** for inpatient discharge-date prediction beat baseline estimates by up to **35.68% in F1**, aligned with MS-GMLOS, and after deployment contributed to an **18.96% reduction in excess hospital days** ([Frontiers Digital Health 2024](https://www.frontiersin.org/journals/digital-health/articles/10.3389/fdgth.2024.1455446/full); [PMC11471729](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11471729/)). Individual-level models predicting discharge within 24h of an index time can be **aggregated to hospital-level discharge counts** for the next 24h — exactly the signal census forecasting needs ([medRxiv discharge flow](https://www.medrxiv.org/content/10.1101/2023.05.02.23289403.full.pdf); [PMC11574281](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11574281/)). Hybrid ICU models combine LOS and "days-to-discharge" framings for sharper near-term predictions ([MDPI ICU hybrid](https://www.mdpi.com/2227-7390/11/23/4773)). Best practice pairs the ML score with **clinical discharge-readiness criteria** (physiologic stability, mobility, social/placement barriers) — the ML model ranks and triages; clinicians confirm.

**Critical realities.** Post-deployment accuracy **declined over time** in the Frontiers study, attributed to train/serve skew — features computed differently in real time than in training ([PMC11471729](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11471729/)). This is the canonical production-ML failure and mandates monitoring (§7).

**Design implications for Zephyrus.**
- **Build first (highest ROI):** a per-patient "discharge in next 24/48h" probability via gradient boosting, **aggregated to unit/hospital expected-discharge counts**. This is the most valuable single model in the platform.
- **Features:** LOS-to-date vs. expected, diagnosis/DRG, recent vitals/labs trajectory, order patterns (consult resolution, PT/OT, line removal), pending results, disposition/placement barriers, day-of-week, attending team. Mine free-text notes for "plan for discharge" language if NLP is in scope.
- **Hybrid:** surface ML rank-ordering alongside an explicit discharge-readiness checklist; never auto-discharge.
- **Metrics:** AUROC/AUPRC and **calibration** at patient level; MAE on aggregate discharge counts; and the operational outcome (excess-days reduction). Watch for train/serve skew explicitly.
- **Seam:** discharge predictor feeds both the census forecaster (§1) and the discharge-planning workflow; pluggable so an Epic-native discharge prediction can substitute.

---

## 3. Length-of-Stay (LOS) Prediction

**Problem.** Predict total LOS (regression) or LOS class (short/long) at or shortly after admission — feeds bed-day planning, expected-discharge-date seeding, and capacity projection for scheduled admissions.

**Methods & evidence.** Tree ensembles dominate. XGBoost reached **MAE/RMSE/MAPE < 3%** on average and, optimized by genetic algorithm, hit **MAE 1.54 / median 1.14 days — a 37% MAE reduction** ([genetic-algorithm tree models, PMC9943622](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9943622/)). Binary short-vs-long LOS classification reaches **AUC up to 0.94** (and ~0.98 in ICU) ([Frontiers LOS COVID](https://www.frontiersin.org/journals/digital-health/articles/10.3389/fdgth.2024.1506071/full)). Explainable ML (SHAP on tree models) is increasingly standard so clinicians/administrators see drivers ([IEEE explainable LOS](https://ieeexplore.ieee.org/document/10577969/)). Integrating heterogeneous data (structured + notes) on MIMIC-III improves LOS prediction ([PMC12408017](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC12408017/)). Top features recur: diagnosis/DRG, admission acuity/source, comorbidity burden, age, insurance, and early labs.

**Important nuance.** LOS distributions are right-skewed; predict on a log scale or model LOS class + survival/time-to-event (Cox C-index ~0.87 reported). For Zephyrus, **LOS prediction is a seeding model for EDD, not the real-time discharge signal** — the §2 daily discharge model is more actionable because it updates with the patient's trajectory, whereas an admission-time LOS estimate goes stale.

**Design implications for Zephyrus.**
- **Build first:** admission-time XGBoost LOS estimate (regression on log-LOS + short/long class) to seed each patient's EDD and project scheduled-admission bed-days.
- **Features:** DRG/diagnosis, age, admission source/acuity, Charlson/Elixhauser comorbidity, early labs/vitals, service line, prior utilization. Avoid insurance/socioeconomic proxies as primary drivers for any resource-allocation use (fairness — see §8).
- **Metrics:** MAE/MAPE in days, C-index for the time-to-event variant; segment error by service line.
- **Seam:** LOS model seeds EDD at admission; the §2 model overrides it daily as the patient progresses. Both pluggable.

---

## 4. ED Arrival / Demand Forecasting

**Problem.** Forecast ED arrivals by hour and the probability each ED patient will be admitted, then aggregate to predicted admissions per time window — the inflow half of the census equation and the primary surge early-warning.

**Methods & evidence.** Two distinct sub-problems:

*Arrival volume forecasting.* Best results from LightGBM, NNAR, SVM-RBF, and temporal deep nets (TCN/LSTM) against Seasonal-Naive/ETS/Ridge baselines. Heavy **feature engineering wins**: cyclical time encodings, weekend/holiday flags, autoregressive lags, volatility, calendar + meteorological predictors — one study built **183 engineered features** ([BMC feature engineering](https://link.springer.com/article/10.1186/s12911-024-02788-6); [MDPI ED arrivals](https://doi.org/10.3390/healthcare14091191)).

*Admission probability from ED.* XGBoost on 109,465 ED visits yielded **AUROC 0.82–0.90**, rising as more visit-time elapses ([npj Digital Medicine, PMC9321296](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9321296/)). The standout operational design **aggregates individual admission probabilities into a probabilistic forecast of total emergency admissions per window**, achieving **MAE 4.0 admissions vs. 6.5 for benchmark** — directly usable for bed planning ([npj Digital Medicine](https://www.nature.com/articles/s41746-022-00649-y)).

**Design implications for Zephyrus.**
- **Build first:** (a) hourly ED arrival forecaster (LightGBM + rich calendar/weather/lag features); (b) per-patient ED→admit probability (XGBoost), **aggregated to expected admissions per window**. Together these are the inflow input to the census model and the surge trigger.
- **Features (arrivals):** hour, day-of-week, holiday, season, weather, lagged arrivals, local event/epidemic signals. **Features (admit prob):** triage acuity (ESI), age, chief complaint, early vitals/labs, arrival mode, time-elapsed-in-visit (re-score as data accrues).
- **Metrics:** MAE/RMSE on arrivals; AUROC + calibration on admit probability; MAE on aggregated admissions; lead time of surge alerts.
- **Seam:** arrival + admit-probability services feed the §1 census forecaster; pluggable so a hospital's own ED admit model can replace ours.

---

## 5. Deterioration & Risk Scores

**Problem.** Anticipate clinical deterioration so the platform can forecast **ICU step-up / step-down demand** and downstream bed pressure. Operationally, Zephyrus cares about deterioration as a *demand signal*, not as the clinical alerting system of record.

**Methods & evidence.** A large 7-hospital Yale New Haven Health study (**362,926 encounters, 4.6% deterioration events**) ranked tools by AUROC: **eCARTv5 0.895 > NEWS2 0.831 > NEWS 0.829 > Rothman Index 0.828 > Epic Deterioration Index 0.808 > MEWS 0.757** ([JAMA Network Open / YNHHS](https://www.ynhhs.org/news/yale-new-haven-health-study-finds-wide-variation-in-performance-of-hospital-early-warning-systems); [JAMA Netw Open](https://www.ovid.com/journals/janop/fulltext/10.1001/jamanetworkopen.2024.38986~early-warning-scores-with-and-without-artificial)). Lead times differed sharply: **eCART 11h, NEWS 8h, NEWS2 6h, MEWS 5h, EDI 1h, RI 0h** — lead time matters as much as AUROC for operations. eCART would have caught ~300 more deteriorating patients while alerting on ~48,000 fewer overall (less alert fatigue). The Rothman Index predicts unplanned ICU readmission, associated with higher mortality and LOS ([PMC10921657](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC10921657/)). **Caution:** vendor scores vary widely in external validation and several are FDA-regulated devices (§8).

**Design implications for Zephyrus.**
- **Build first:** do **not** rebuild a clinical deterioration model. Compute the **open, transparent NEWS2/MEWS** from vitals as a rule-based, non-device signal, and **ingest existing vendor scores (EDI, eCART, Rothman) where deployed** via the pluggable seam. Map rising aggregate deterioration to **predicted ICU step-up demand**.
- **Features:** vitals (RR, SpO2, temp, BP, HR, consciousness) for NEWS2; otherwise consume the score the hospital already trusts.
- **Metrics:** for the demand model, accuracy of forecasted ICU transfers/step-ups; for any score we surface, report its published AUROC and lead time — do not re-derive clinical claims.
- **Governance:** treating a deterioration score as actionable clinical alerting crosses into FDA device territory; Zephyrus uses it as an aggregate operational demand input only, with the clinical alert owned by the EHR.

---

## 6. Readmission Risk

**Problem.** Flag patients at high 30-day readmission risk — a discharge-safety guardrail that tempers aggressive discharge-acceleration recommendations.

**Methods & evidence.** Traditional indices are modest: **LACE AUC ~0.69, HOSPITAL AUC ~0.69**, roughly equivalent overall, though HOSPITAL outperforms LACE on CMS target conditions and some cancers ([AJMQ LACE vs HOSPITAL, PMC9241658](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9241658/)). LACE generalizes poorly to some populations (home-care AUC 0.598). **ML decisively beats indices:** a gradient-boosting model with machine-learned features reached **AUC 0.83 vs. LACE 0.66** ([PMC9700920](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9700920/)); refining LACE with demographics, comorbidities, and labs also beats vanilla LACE.

**Design implications for Zephyrus.**
- **Build first:** ship **LACE/HOSPITAL as transparent rule-based scores** (cheap, explainable, non-device) for v1; add a gradient-boosting readmission model in v2 when discharge-safety value justifies it.
- **Role in platform:** readmission risk is a **brake**, not a throttle — when the discharge model (§2) ranks a patient for today's discharge but readmission risk is high, surface the tension to the care team rather than counting the bed as freed.
- **Features:** prior admissions, ED visits, comorbidity burden, LOS, discharge disposition, polypharmacy, social determinants (used cautiously — fairness §8).
- **Metrics:** AUROC/AUPRC, calibration, and net-benefit; track whether discharge-acceleration recommendations increase readmissions (the key safety KPI).
- **Seam:** pluggable; honor an existing certified readmission DSI if the hospital runs one.

---

## 7. Production ML Realities

A predictive platform lives or dies on operations, not on offline AUROC. Two cautionary tales anchor this.

**The Epic Sepsis Model.** Implemented at **hundreds of US hospitals without prior external validation**, it was externally validated on 27,697 Michigan Medicine patients and found to have **AUC 0.63, sensitivity 33%, PPV 12%**, with poor calibration and severe alert fatigue ([Wong et al., JAMA Intern Med 2021](https://jamanetwork.com/journals/jamainternalmedicine/fullarticle/2781307); [editorial PMID 34152360](https://pubmed.ncbi.nlm.nih.gov/34152360/)). Lesson: **never deploy a vendor or internal model without local external validation and calibration on your own population.**

**Train/serve skew & drift.** The discharge model in §2 lost accuracy post-deployment because real-time features differed from training features ([PMC11471729](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11471729/)). Standard MLOps practice mandates continuous monitoring for **data drift, concept drift, and degradation**, with calibration metrics (Brier score), drift detectors on input distributions, and **automated retraining triggers** ([Encord model drift](https://encord.com/blog/model-drift-best-practices/); [TDS drift detection](https://towardsdatascience.com/how-to-detect-model-drift-in-mlops-monitoring-7a039c22eaf9/); [MLOps survey arXiv 2408.11112](https://arxiv.org/pdf/2408.11112)).

**Feature pipeline & latency.** EHR feature pipelines should be **FHIR-based and reproducible**, with the *same* code computing features in training and serving to eliminate skew. Real-time scoring (ED admit probability, discharge-likelihood refresh) needs sub-second to few-second latency; batch census forecasts can run on a schedule. A feature store enforcing offline/online parity is the canonical fix.

**Design implications for Zephyrus.**
- **Build first:** a model-monitoring service from day one — log every prediction with inputs and outcomes; track AUROC/MAE/Brier over rolling windows; alert on drift; dashboard calibration. No model ships without local validation.
- **Feature store:** one FHIR-sourced feature pipeline shared by train and serve; version features; capture the exact feature vector at scoring time for audit and skew detection.
- **Latency tiers:** real-time tier (patient-level admit/discharge/deterioration re-scoring, seconds) vs. batch tier (census/arrival forecasts, scheduled).
- **Seam:** the pluggable model service exposes `score()`, `explain()`, and `health()`; monitoring wraps every model uniformly regardless of build-vs-buy origin.

---

## 8. Regulation, Fairness & Governance

This determines **what Zephyrus can ship without FDA clearance** versus what crosses the line.

**FDA / SaMD & the Cures Act CDS exemption.** Revised FDA CDS guidance (Jan 6, 2026, superseding 2022) clarifies the **21st Century Cures Act §3060 Non-Device CDS exemption**. Software is **Non-Device CDS** only when **all four** criteria hold: (1) it does not acquire/process/analyze a medical image or signal; (2) it displays/analyzes/prints medical information; (3) it provides recommendations *to a healthcare professional*; and (4) the HCP **can independently review the basis** for the recommendation ([FDA CDS guidance](https://www.fda.gov/regulatory-information/search-fda-guidance-documents/clinical-decision-support-software); [Arnold & Porter](https://www.arnoldporter.com/en/perspectives/advisories/2026/01/fda-cuts-red-tape-on-clinical-decision-support-software)). The fourth criterion is the trap: an **un-explainable predictive model** (e.g., a black-box sepsis or deterioration predictor whose basis can't be reviewed), or a **time-critical triage/risk-stratification tool**, is **Device CDS requiring 510(k)/De Novo** ([Nixon Law](https://www.nixonlawgroup.com/resources/fda-relaxes-clinical-decision-support-and-general-wellness-guidance-what-it-means-for-generative-ai-and-consumer-wearables); [Mayo Clinic Proc Digital Health](https://www.mcpdigitalhealth.org/article/S2949-7612(25)00038-0/fulltext)).

**Where Zephyrus lands.** Operational/administrative forecasts — **census, bed demand, ED arrival volume, expected discharge counts, LOS-based bed planning** — are about *resource logistics*, not diagnosis/treatment of an individual, and are presented to operational staff with reviewable bases. These sit safely **outside FDA device regulation**. The line is crossed if Zephyrus outputs an **individual patient-level clinical recommendation** (e.g., "this patient is deteriorating, escalate") via an un-reviewable model, or drives **time-critical clinical triage**. Mitigation: keep clinical deterioration/sepsis alerting in the EHR's cleared tools; Zephyrus consumes those scores as *demand inputs* and keeps its own outputs operational, transparent, and HCP-reviewable.

**ONC HTI-1 / DSI algorithm transparency.** The HTI-1 final rule (Fed. Reg. Jan 9, 2024) created first-of-its-kind transparency rules for **Predictive Decision Support Interventions** in certified health IT: **31 source attributes** for Predictive DSIs (13 for evidence-based), plus required risk-management ("FAVES": fair, appropriate, valid, effective, safe) and real-world testing. DSI is part of the Base EHR definition as of Jan 1, 2025 ([Federal Register HTI-1](https://www.federalregister.gov/documents/2024/01/09/2023-28857/health-data-technology-and-interoperability-certification-program-updates-algorithm-transparency-and); [ONC DSI fact sheet](https://www.healthit.gov/sites/default/files/page/2023-12/HTI-1_DSI_fact%20sheet_508.pdf); [Mintz](https://www.mintz.com/insights-center/viewpoints/2146/2024-01-08-hhs-onc-hti-1-final-rule-introduces-new-transparency)). Even if Zephyrus is not certified health IT, **adopting the 31-attribute "model card" disclosure is the de facto standard** and a procurement requirement at hospitals.

**Algorithmic bias.** Obermeyer et al. (Science 2019) showed a widely used population-health algorithm was racially biased because it used **health cost as a proxy for health need**: at the 97th percentile, Black patients had 26% more chronic illness than White patients at the same score; correcting the proxy raised Black patients flagged for extra care from 17.7% toward 46.5% and cut bias ~84% ([Obermeyer 2019, Science](https://www.science.org/doi/10.1126/science.aax2342); [PubMed 31649194](https://pubmed.ncbi.nlm.nih.gov/31649194/)). Direct lesson for Zephyrus: **never use cost or utilization as a label/proxy for clinical need or priority**, and audit every resource-allocation model for disparate impact across race, sex, age, language, and payer.

**Design implications for Zephyrus.**
- **Stay non-device by design:** ship operational forecasts (census, ED volume, discharge counts, LOS bed-planning) with reviewable bases; keep patient-level *clinical* alerting in cleared EHR tools and consume their scores.
- **Explainability is a regulatory feature, not a nicety:** SHAP/feature-attribution on every patient-level score so the HCP can independently review the basis — this is what keeps models on the Non-Device side of the Cures Act line.
- **Model cards:** publish HTI-1-style source attributes (training data, population, performance, validation, fairness, intended use, known limitations) for every model.
- **Fairness pipeline:** never use cost/utilization as a need proxy; run subgroup performance and disparate-impact audits pre-deployment and continuously; gate any allocation-affecting model behind a fairness review.
- **Governance registry:** every model has an owner, validation record, monitoring status, and a defined intended-use boundary; the pluggable seam carries this metadata.

---

## Build-vs-Buy / Seam Strategy Summary

| Predictor | v1 recommendation | Build / Buy | FDA posture |
|---|---|---|---|
| Census/occupancy | SARIMAX/Prophet + LightGBM, flow-decomposed | **Build** | Non-device (operational) |
| Discharge-today/EDD | XGBoost daily, aggregate to counts | **Build (top ROI)** | Non-device if HCP-reviewable |
| LOS | XGBoost at admission, seeds EDD | **Build** | Non-device |
| ED arrivals | LightGBM hourly + engineered features | **Build** | Non-device |
| ED admit probability | XGBoost, aggregate to admissions | **Build** | Non-device (aggregate) |
| Deterioration | NEWS2 (rule) + ingest vendor (EDI/eCART/Rothman) | **Buy/ingest** | Vendor device; we consume as demand |
| Readmission | LACE/HOSPITAL rule v1 → GBM v2 | **Build (rules first)** | Non-device |
| Monitoring/MLOps | Drift + calibration + feature store | **Build (day one)** | Cross-cutting |

The unifying seam: a **pluggable model service** with `score()/explain()/health()` and HTI-1 metadata, so any predictor can be internally trained, vendor-supplied, or rule-based, while the optimization engine and monitoring layer stay model-agnostic. Build the operational forecasters (high value, non-device, our data advantage); buy/ingest the clinical deterioration scores (device-regulated, already deployed); and never ship any model without local external validation, calibration, drift monitoring, and a fairness audit.

---

## Sources

- NHS MSARIMA bed occupancy — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9021768/
- ARIMA/Prophet/LSTM comparison — https://www.researchgate.net/publication/387701628_A_Comparative_Study_of_ARIMA_Prophet_and_LSTM_for_Time_Series_Prediction
- Hybrid ARIMA-Prophet — https://www.sciencedirect.com/science/article/pii/S2590123025017748
- Bayesian deep-learning bed occupancy (Nature Sci Rep 2025) — https://www.nature.com/articles/s41598-025-22001-6
- TimeGPT forecasting benchmark — https://www.sciencedirect.com/science/article/pii/S2666827025001847
- Discharge-date ML in clinical workflow (Frontiers) — https://www.frontiersin.org/journals/digital-health/articles/10.3389/fdgth.2024.1455446/full
- Discharge-date ML (PMC) — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11471729/
- Patient-flow discharge prediction (medRxiv) — https://www.medrxiv.org/content/10.1101/2023.05.02.23289403.full.pdf
- Individual + hospital-level discharge ML — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11574281/
- ICU hybrid LOS + days-to-discharge — https://www.mdpi.com/2227-7390/11/23/4773
- LOS tree models + genetic algorithm — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9943622/
- LOS before/during COVID (Frontiers) — https://www.frontiersin.org/journals/digital-health/articles/10.3389/fdgth.2024.1506071/full
- Explainable LOS (IEEE) — https://ieeexplore.ieee.org/document/10577969/
- LOS via heterogeneous MIMIC-III data — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC12408017/
- ED arrivals feature engineering (BMC) — https://link.springer.com/article/10.1186/s12911-024-02788-6
- ED arrivals ML (MDPI Healthcare) — https://doi.org/10.3390/healthcare14091191
- Real-time aggregated ED admission prediction (npj Digital Medicine) — https://www.nature.com/articles/s41746-022-00649-y
- Real-time aggregated ED admission (PMC) — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9321296/
- Yale New Haven early-warning comparison — https://www.ynhhs.org/news/yale-new-haven-health-study-finds-wide-variation-in-performance-of-hospital-early-warning-systems
- Early Warning Scores with/without AI (JAMA Netw Open) — https://www.ovid.com/journals/janop/fulltext/10.1001/jamanetworkopen.2024.38986~early-warning-scores-with-and-without-artificial
- Rothman Index ICU readmission — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC10921657/
- LACE vs HOSPITAL (AJMQ) — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9241658/
- ML readmission with machine-learned features — https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9700920/
- Epic Sepsis Model external validation (Wong et al., JAMA Intern Med) — https://jamanetwork.com/journals/jamainternalmedicine/fullarticle/2781307
- Epic Sepsis Model editorial — https://pubmed.ncbi.nlm.nih.gov/34152360/
- Model drift best practices (Encord) — https://encord.com/blog/model-drift-best-practices/
- Drift detection in MLOps (TDS) — https://towardsdatascience.com/how-to-detect-model-drift-in-mlops-monitoring-7a039c22eaf9/
- MLOps experimentation/deployment/monitoring survey — https://arxiv.org/pdf/2408.11112
- FDA CDS Software guidance — https://www.fda.gov/regulatory-information/search-fda-guidance-documents/clinical-decision-support-software
- FDA cuts red tape on CDS (Arnold & Porter) — https://www.arnoldporter.com/en/perspectives/advisories/2026/01/fda-cuts-red-tape-on-clinical-decision-support-software
- FDA CDS / GenAI relaxation (Nixon Law Group) — https://www.nixonlawgroup.com/resources/fda-relaxes-clinical-decision-support-and-general-wellness-guidance-what-it-means-for-generative-ai-and-consumer-wearables
- FDA regulation of clinical software in AI/ML era (Mayo Clinic Proc Digital Health) — https://www.mcpdigitalhealth.org/article/S2949-7612(25)00038-0/fulltext
- ONC HTI-1 Final Rule (Federal Register) — https://www.federalregister.gov/documents/2024/01/09/2023-28857/health-data-technology-and-interoperability-certification-program-updates-algorithm-transparency-and
- ONC DSI fact sheet — https://www.healthit.gov/sites/default/files/page/2023-12/HTI-1_DSI_fact%20sheet_508.pdf
- HTI-1 transparency requirements (Mintz) — https://www.mintz.com/insights-center/viewpoints/2146/2024-01-08-hhs-onc-hti-1-final-rule-introduces-new-transparency
- Obermeyer et al. 2019, racial bias (Science) — https://www.science.org/doi/10.1126/science.aax2342
- Obermeyer 2019 (PubMed) — https://pubmed.ncbi.nlm.nih.gov/31649194/
