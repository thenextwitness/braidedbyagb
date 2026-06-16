package com.braidedbyagb.admin.data.db

import android.content.Context
import androidx.room.*

// ── Entity ────────────────────────────────────────────────
@Entity(tableName = "cache")
data class CacheEntry(
    @PrimaryKey val key: String,
    val json: String,
    val updatedAt: Long = System.currentTimeMillis()
)

// ── DAO ───────────────────────────────────────────────────
@Dao
interface CacheDao {
    @Query("SELECT * FROM cache WHERE `key` = :key LIMIT 1")
    suspend fun get(key: String): CacheEntry?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun put(entry: CacheEntry)

    @Query("DELETE FROM cache WHERE `key` = :key")
    suspend fun delete(key: String)

    @Query("DELETE FROM cache")
    suspend fun clearAll()
}

// ── Database ──────────────────────────────────────────────
@Database(entities = [CacheEntry::class], version = 1, exportSchema = false)
abstract class AppDatabase : RoomDatabase() {
    abstract fun cacheDao(): CacheDao

    companion object {
        @Volatile private var INSTANCE: AppDatabase? = null

        fun getInstance(context: Context): AppDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "braidedbyagb_cache.db"
                ).build().also { INSTANCE = it }
            }
    }
}
