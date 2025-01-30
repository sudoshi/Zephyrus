import pandas as pd
import numpy as np
from typing import Tuple, List

def load_excel_files() -> Tuple[pd.DataFrame, pd.DataFrame]:
    """Load both Excel files and return as DataFrames."""
    try:
        df_final = pd.read_excel('Block Schedule Extracts Final - v2 DATA.xlsx')
        df_releases = pd.read_excel('Block Schedule Extracts with First Releases - v2 DATA.xlsx')
        return df_final, df_releases
    except Exception as e:
        raise Exception(f"Error loading Excel files: {str(e)}")

def analyze_columns(df_final: pd.DataFrame, df_releases: pd.DataFrame) -> None:
    """Print analysis of columns in both DataFrames."""
    final_cols = set(df_final.columns)
    releases_cols = set(df_releases.columns)
    
    print("\nColumn Analysis:")
    print("=" * 50)
    print(f"Final file columns: {len(final_cols)}")
    print(f"Releases file columns: {len(releases_cols)}")
    
    print("\nColumns unique to Final file:")
    print(final_cols - releases_cols)
    
    print("\nColumns unique to Releases file:")
    print(releases_cols - final_cols)
    
    print("\nCommon columns:")
    print(final_cols.intersection(releases_cols))

def merge_dataframes(df_final: pd.DataFrame, df_releases: pd.DataFrame) -> pd.DataFrame:
    """Merge the two DataFrames, keeping all columns from both."""
    # Combine all unique columns
    all_columns = list(set(df_final.columns) | set(df_releases.columns))
    
    # Ensure both DataFrames have all columns, filling missing ones with NaN
    for col in all_columns:
        if col not in df_final.columns:
            df_final[col] = np.nan
        if col not in df_releases.columns:
            df_releases[col] = np.nan
    
    # Concatenate the DataFrames
    df_merged = pd.concat([df_final, df_releases], ignore_index=True)
    
    # Drop duplicates if any
    df_merged = df_merged.drop_duplicates()
    
    return df_merged

def main():
    print("Loading Excel files...")
    df_final, df_releases = load_excel_files()
    
    print("\nInitial row counts:")
    print(f"Final file: {len(df_final)} rows")
    print(f"Releases file: {len(df_releases)} rows")
    
    analyze_columns(df_final, df_releases)
    
    print("\nMerging files...")
    df_merged = merge_dataframes(df_final, df_releases)
    
    print(f"\nFinal merged file: {len(df_merged)} rows")
    
    print("\nExporting to CSV...")
    df_merged.to_csv('merged_block_schedules.csv', index=False)
    print("Export complete: merged_block_schedules.csv")

if __name__ == "__main__":
    main()

