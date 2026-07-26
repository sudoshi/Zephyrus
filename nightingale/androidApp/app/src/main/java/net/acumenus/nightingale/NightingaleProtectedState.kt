package net.acumenus.nightingale

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

internal object NightingaleProtectedStateNamespace {
    const val KEYSTORE_ALIAS = "net.acumenus.nightingale.protected-state-key.v1"
    const val PREFERENCES_FILE =
        "net.acumenus.nightingale.protected-state-ciphertext.v1"
    const val FUTURE_SESSION_BINDING = "future-session-binding-v1"
    const val ENVELOPE_VERSION: Byte = 1

    val authenticatedContext: ByteArray
        get() =
            "net.acumenus.nightingale|protected-state-v1|$FUTURE_SESSION_BINDING"
                .encodeToByteArray()
}

internal data class NightingaleProtectedStateDeletionOutcome(
    val keyRemoved: Boolean,
    val ciphertextRemoved: Boolean,
    val wasAlreadyAbsent: Boolean,
) {
    val complete: Boolean
        get() = keyRemoved && ciphertextRemoved
}

internal class NightingaleProtectedStateUnavailableException(
    operation: String,
    cause: Throwable,
) : IllegalStateException("Nightingale protected state is unavailable during $operation.", cause)

internal class NightingaleProtectedStateDeletionException(
    val keyRemoved: Boolean,
    val ciphertextRemoved: Boolean,
    cause: Throwable,
) : IllegalStateException("Nightingale protected state deletion did not complete.", cause)

internal interface NightingaleProtectedStateStoring {
    fun readFutureSessionBinding(): ByteArray?
    fun writeFutureSessionBinding(value: ByteArray)
    fun deleteAll(): NightingaleProtectedStateDeletionOutcome
}

/**
 * Dormant device-only protected storage for the pre-identity foundation.
 *
 * No production caller constructs this store. The only approved current value is a
 * synthetic instrumentation canary. There is intentionally no access-token,
 * refresh-token, device-UUID, or legacy migration API.
 */
internal class AndroidKeystoreNightingaleProtectedStateStore(context: Context) :
    NightingaleProtectedStateStoring {
    private val preferences =
        context.applicationContext.getSharedPreferences(
            NightingaleProtectedStateNamespace.PREFERENCES_FILE,
            Context.MODE_PRIVATE,
        )

    override fun readFutureSessionBinding(): ByteArray? {
        val encoded = try {
            preferences.getString(
                NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
                null,
            )
        } catch (error: Exception) {
            throw unavailable("read-record", error)
        } ?: return null

        return try {
            val envelope = decodeEnvelope(Base64.decode(encoded, Base64.NO_WRAP))
            val key = existingKey()
                ?: throw IllegalStateException("Protected ciphertext has no cryptographic key.")
            Cipher.getInstance(CIPHER_TRANSFORMATION).run {
                init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(GCM_TAG_BITS, envelope.iv))
                updateAAD(NightingaleProtectedStateNamespace.authenticatedContext)
                doFinal(envelope.ciphertext)
            }
        } catch (error: Exception) {
            throw unavailable("decrypt", error)
        }
    }

    override fun writeFutureSessionBinding(value: ByteArray) {
        require(value.isNotEmpty()) { "Nightingale protected state cannot be empty." }

        try {
            val cipher = Cipher.getInstance(CIPHER_TRANSFORMATION)
            cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
            cipher.updateAAD(NightingaleProtectedStateNamespace.authenticatedContext)
            val envelope = encodeEnvelope(cipher.iv, cipher.doFinal(value))
            val persisted = preferences.edit()
                .putString(
                    NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING,
                    Base64.encodeToString(envelope, Base64.NO_WRAP),
                )
                .commit()
            check(persisted) { "Protected ciphertext commit failed." }
        } catch (error: Exception) {
            // A failed replacement must not leave an older binding usable.
            val deletionFailure = runCatching { deleteAll() }.exceptionOrNull()
            throw unavailable("write", deletionFailure ?: error)
        }
    }

    override fun deleteAll(): NightingaleProtectedStateDeletionOutcome {
        var keyRemoved = false
        var ciphertextRemoved = false
        var firstFailure: Throwable? = null
        var hadKey: Boolean? = null
        var hadCiphertext: Boolean? = null

        try {
            val keyStore = keyStore()
            hadKey = keyStore.containsAlias(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS)
            if (hadKey == true) {
                keyStore.deleteEntry(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS)
            }
            keyRemoved =
                !keyStore.containsAlias(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS)
        } catch (error: Exception) {
            firstFailure = error
        }

        try {
            hadCiphertext =
                preferences.contains(NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING)
            ciphertextRemoved = preferences.edit()
                .remove(NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING)
                .commit() &&
                !preferences.contains(NightingaleProtectedStateNamespace.FUTURE_SESSION_BINDING)
        } catch (error: Exception) {
            if (firstFailure == null) firstFailure = error
        }

        if (!keyRemoved || !ciphertextRemoved) {
            throw NightingaleProtectedStateDeletionException(
                keyRemoved = keyRemoved,
                ciphertextRemoved = ciphertextRemoved,
                cause = firstFailure ?: IllegalStateException("Deletion verification failed."),
            )
        }

        return NightingaleProtectedStateDeletionOutcome(
            keyRemoved = true,
            ciphertextRemoved = true,
            wasAlreadyAbsent = hadKey == false && hadCiphertext == false,
        )
    }

    private fun getOrCreateKey(): SecretKey {
        existingKey()?.let { return it }
        return KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES,
            ANDROID_KEYSTORE,
        ).run {
            init(
                KeyGenParameterSpec.Builder(
                    NightingaleProtectedStateNamespace.KEYSTORE_ALIAS,
                    KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
                )
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(AES_KEY_BITS)
                    .setRandomizedEncryptionRequired(true)
                    .setUserAuthenticationRequired(false)
                    .build(),
            )
            generateKey()
        }
    }

    private fun existingKey(): SecretKey? =
        keyStore().getKey(NightingaleProtectedStateNamespace.KEYSTORE_ALIAS, null)
            as? SecretKey

    private fun keyStore(): KeyStore =
        KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }

    private data class EncryptedEnvelope(
        val iv: ByteArray,
        val ciphertext: ByteArray,
    )

    private fun encodeEnvelope(iv: ByteArray, ciphertext: ByteArray): ByteArray {
        require(iv.size == GCM_IV_BYTES)
        return byteArrayOf(
            NightingaleProtectedStateNamespace.ENVELOPE_VERSION,
            iv.size.toByte(),
        ) + iv + ciphertext
    }

    private fun decodeEnvelope(envelope: ByteArray): EncryptedEnvelope {
        require(envelope.size > ENVELOPE_HEADER_BYTES)
        require(envelope[0] == NightingaleProtectedStateNamespace.ENVELOPE_VERSION)
        val ivLength = envelope[1].toUByte().toInt()
        require(ivLength == GCM_IV_BYTES)
        val ivEnd = ENVELOPE_HEADER_BYTES + ivLength
        require(ivEnd < envelope.size)
        return EncryptedEnvelope(
            iv = envelope.copyOfRange(ENVELOPE_HEADER_BYTES, ivEnd),
            ciphertext = envelope.copyOfRange(ivEnd, envelope.size),
        )
    }

    private fun unavailable(
        operation: String,
        error: Throwable,
    ): NightingaleProtectedStateUnavailableException =
        if (error is NightingaleProtectedStateUnavailableException) {
            error
        } else {
            NightingaleProtectedStateUnavailableException(operation, error)
        }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val CIPHER_TRANSFORMATION = "AES/GCM/NoPadding"
        const val AES_KEY_BITS = 256
        const val GCM_TAG_BITS = 128
        const val GCM_IV_BYTES = 12
        const val ENVELOPE_HEADER_BYTES = 2
    }
}

internal enum class NightingaleVolatileInputClearReason {
    APPLICATION_INACTIVE,
    LOGOUT,
    IDENTITY_TRANSITION,
    RECOVERY,
    REVOCATION,
    LOCAL_REMOVAL,
}

/**
 * Process-memory-only composition state.
 *
 * Clearing releases the app's String reference. Kotlin/JVM does not provide a reliable
 * immutable String zeroization guarantee, so this class does not claim memory overwrite.
 */
internal class NightingaleVolatileInputState {
    var draft: String = ""
        private set
    var lastClearReason: NightingaleVolatileInputClearReason? = null
        private set

    val hasDraft: Boolean
        get() = draft.isNotEmpty()

    fun replaceDraftForComposition(value: String) {
        draft = value
    }

    fun clear(reason: NightingaleVolatileInputClearReason) {
        draft = ""
        lastClearReason = reason
    }
}
